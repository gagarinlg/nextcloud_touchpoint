<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Notification;

use OCP\IGroupManager;
use OCP\IUserManager;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Dispatches Touchpoint notifications ('note_shared' / 'note_mention') via
 * OCP\Notification\IManager. Rendering of these subjects into a parsed/rich
 * subject and a deep link happens in Notifier (OCP\Notification\INotifier),
 * which runs later/lazily when a client actually fetches the notification.
 *
 * All dispatch here is best-effort: a failure to create/notify must never
 * abort the note save that triggered it, so every public method catches and
 * logs.
 */
class NotificationService {

    /**
     * Notification subject identifiers shared with Notifier (the render
     * side). Owned here, on the dispatch/producer side, since dispatch()
     * decides what gets sent — Notifier::SUBJECT_* are aliases of these for
     * backward compatibility with existing call sites/tests; do not
     * duplicate the literal strings elsewhere.
     */
    public const SUBJECT_NOTE_SHARED = 'note_shared';
    public const SUBJECT_NOTE_MENTION = 'note_mention';

    /**
     * Matches an @mention token, e.g. "@jdoe" or "@jane.doe". Stops at
     * whitespace or common punctuation that would otherwise be swallowed into
     * the candidate user id (matches the convention used by Nextcloud Talk's
     * mention syntax: word characters, dot, dash, underscore, @ for email-style
     * uids). The candidate is validated against IUserManager::userExists()
     * afterwards, so an over-eager match here is harmless — it simply fails
     * validation and is discarded.
     *
     * Known limitation: allowing '@' inside the captured token means two
     * mentions with no separator between them, e.g. "@alice@bob", parse as a
     * single candidate "alice@bob" rather than two mentions. That candidate
     * almost never resolves via userExists(), so both intended mentions are
     * silently dropped. Authors should separate consecutive mentions with
     * whitespace or punctuation (e.g. "@alice @bob") to ensure both are
     * recognised; see testExtractMentionsAdjacentMentionsWithNoSeparatorAreDropped.
     */
    private const MENTION_PATTERN = '/@([\w.\-@]+)/u';

    /** Maximum number of distinct @mentions processed per note save. */
    private const MAX_MENTIONS_PER_NOTE = 50;

    /**
     * Maximum number of DISTINCT @-token candidates (valid or not) scanned per
     * note before giving up, even if MAX_MENTIONS_PER_NOTE valid mentions were
     * never reached. Bounds the number of IUserManager::userExists() calls
     * (which commonly hit a remote backend such as LDAP/SAML) a single
     * create()/update() can trigger: without this, a note body packed with
     * thousands of distinct fabricated "@token" candidates that never resolve
     * to a real user would call userExists() once per candidate with no upper
     * bound tied to the (valid-match-only) MAX_MENTIONS_PER_NOTE cap.
     */
    private const MAX_CANDIDATES_SCANNED = 200;

    /**
     * Maximum number of individual user ids a single note-share fan-out will
     * notify. expandShareTargetsToUserIds() expands 'group' targets to their
     * full membership via IGroupManager with no inherent bound; without this
     * cap, sharing a note with a large group (e.g. thousands of members) would
     * make NoteService::create()/update() issue that many sequential
     * IManager::notify() calls synchronously inside the HTTP request. Mirrors
     * MAX_MENTIONS_PER_NOTE's rationale for the mention-scanning path. See
     * docs/ARCHITECTURE.md's Notification section.
     */
    private const MAX_NOTIFY_RECIPIENTS_PER_NOTE = 200;

    public function __construct(
        private INotificationManager $notificationManager,
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Notify $targetUserId that $actorUserId shared note $noteId with them.
     * No-op (and never notifies) when the target is the actor themselves.
     * Exceptions are caught and logged — dispatch failure must never fail the
     * note save that triggered it. $noteTitle is carried through to Notifier
     * so the rendered notification can show which note this is about, rather
     * than an identical-looking row for every share/mention (truncated to 60
     * chars there to bound layout size).
     */
    public function sendShareNotification(int $noteId, string $actorUserId, string $targetUserId, string $noteTitle = ''): void {
        $this->dispatch(self::SUBJECT_NOTE_SHARED, $noteId, $actorUserId, $targetUserId, $noteTitle);
    }

    /**
     * Notify $mentionedUserId that $actorUserId mentioned them in note
     * $noteId. No-op (and never notifies) when the mentioned user is the
     * actor themselves. Exceptions are caught and logged — dispatch failure
     * must never fail the note save that triggered it. See sendShareNotification()
     * for $noteTitle's purpose.
     */
    public function sendMentionNotification(int $noteId, string $actorUserId, string $mentionedUserId, string $noteTitle = ''): void {
        $this->dispatch(self::SUBJECT_NOTE_MENTION, $noteId, $actorUserId, $mentionedUserId, $noteTitle);
    }

    /**
     * Shared dispatch logic behind sendShareNotification()/sendMentionNotification():
     * guard against a blank/self target, build the notification, and swallow +
     * log any failure so it can never fail the note save that triggered it.
     */
    private function dispatch(string $subject, int $noteId, string $actorUserId, string $targetUserId, string $noteTitle = ''): void {
        if ($targetUserId === '' || $targetUserId === $actorUserId) {
            return;
        }
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('touchpoint')
                ->setUser($targetUserId)
                ->setObject('note', (string) $noteId)
                ->setSubject($subject, [
                    'noteId' => $noteId,
                    'actorUid' => $actorUserId,
                    'noteTitle' => $noteTitle,
                ])
                ->setDateTime(new \DateTime());
            $this->notificationManager->notify($notification);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Touchpoint: failed to dispatch ' . $subject . ' notification',
                ['exception' => $e, 'noteId' => $noteId, 'actorUserId' => $actorUserId, 'targetUserId' => $targetUserId]
            );
        }
    }

    /**
     * Expand a list of {type, id} share targets into the flat set of
     * individual user ids that should be notified: 'user' targets pass
     * through as-is; 'group' targets are expanded to their current members
     * via IGroupManager. Never includes $actorUserId (the actor is never
     * notified about their own action), and de-duplicates so a user who is
     * both directly shared with and a member of a shared group is only
     * notified once.
     *
     * A group lookup failure (e.g. the group was deleted concurrently) is
     * caught and logged; the remaining targets are still expanded.
     *
     * Bounded to MAX_NOTIFY_RECIPIENTS_PER_NOTE distinct user ids in the
     * RETURNED set: a note shared with a very large group (e.g. an
     * "all-staff" group with thousands of members) would otherwise make the
     * caller (NoteService, running synchronously inside the HTTP request)
     * issue one IManager::notify() call per member with no upper bound.
     *
     * The cap is applied ONLY to fan-out contributed by 'group' expansion,
     * and 'group' targets are processed strictly after every 'user' target
     * regardless of array order. A direct 'user' target always costs exactly
     * one notify() call — it can never cause runaway fan-out — so it must
     * never be dropped merely because it appeared after a large group in
     * $targets. Once a group's expansion pushes the running total over the
     * cap, remaining GROUP targets are skipped and a warning is logged; the
     * already-collected set (all direct users + whatever group expansion fit
     * under the cap) is still truncated to the cap before being returned, so
     * the number of notify() calls the caller ends up making is always <=
     * the cap regardless of how large any single group in $targets is.
     *
     * @param array<array{type?: string, id?: string}> $targets
     * @return string[]
     */
    public function expandShareTargetsToUserIds(array $targets, string $actorUserId): array {
        $userIds = [];
        $groupTargetIds = [];

        // Pass 1: collect every direct user target unconditionally — fixed,
        // bounded cost of exactly one entry each, never subject to the cap.
        foreach ($targets as $target) {
            $type = (string) ($target['type'] ?? '');
            $id = (string) ($target['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($type === 'user') {
                $userIds[$id] = true;
            } elseif ($type === 'group') {
                $groupTargetIds[] = $id;
            }
        }

        // Pass 2: expand groups, capping only the growth they contribute.
        // NOTE: MAX_NOTIFY_RECIPIENTS_PER_NOTE bounds the number of notify()
        // calls this method eventually triggers — it does NOT bound the cost
        // of resolving a single oversized group's membership. getUsers()
        // below always returns that group's FULL member list before the cap
        // below can even be checked (NC's IGroupManager/IGroup expose no
        // paginated/streaming membership query), so one very large group can
        // still force a slow getUsers() round-trip and a large in-memory
        // array before truncation kicks in.
        foreach ($groupTargetIds as $id) {
            try {
                $group = $this->groupManager->get($id);
            } catch (Throwable $e) {
                $this->logger->warning(
                    'Touchpoint: failed to resolve group for share notification expansion',
                    ['exception' => $e, 'groupId' => $id]
                );
                continue;
            }
            if ($group === null) {
                continue;
            }
            foreach ($group->getUsers() as $member) {
                $userIds[$member->getUID()] = true;
            }
            if (count($userIds) > self::MAX_NOTIFY_RECIPIENTS_PER_NOTE) {
                $this->logger->warning(
                    'Touchpoint: share notification fan-out truncated at cap',
                    ['cap' => self::MAX_NOTIFY_RECIPIENTS_PER_NOTE]
                );
                break;
            }
        }

        unset($userIds[$actorUserId]);
        $userIds = array_keys($userIds);
        if (count($userIds) > self::MAX_NOTIFY_RECIPIENTS_PER_NOTE) {
            $userIds = array_slice($userIds, 0, self::MAX_NOTIFY_RECIPIENTS_PER_NOTE);
        }
        return $userIds;
    }

    /**
     * Scan note content for @userId mention patterns and return the distinct,
     * order-preserved set of candidate ids that resolve to an existing user
     * via IUserManager::userExists(). Never includes $actorUserId. Bounded to
     * MAX_MENTIONS_PER_NOTE distinct valid mentions so a pathological note
     * body cannot trigger unbounded user-lookup/notification fan-out.
     *
     * Also bounded to MAX_CANDIDATES_SCANNED distinct candidates (valid or
     * not) inspected via userExists(): MAX_MENTIONS_PER_NOTE only caps VALID
     * matches, so without this second cap a note body packed with thousands
     * of distinct fabricated "@token"s that never resolve to a real user
     * would still call userExists() once per candidate — a real per-request
     * cost against the identity backend (LDAP/SAML/etc. are not in-memory
     * checks).
     *
     * @return string[]
     */
    public function extractMentionedUserIds(?string $content, string $actorUserId): array {
        if ($content === null || $content === '') {
            return [];
        }
        if (!preg_match_all(self::MENTION_PATTERN, $content, $matches)) {
            return [];
        }

        $result = [];
        $seen = [];
        foreach ($matches[1] as $candidate) {
            // $candidate can never be '' here: it comes from the capture group
            // in MENTION_PATTERN, which requires at least one [\w.\-@] character.
            if ($candidate === $actorUserId || isset($seen[$candidate])) {
                continue;
            }
            $seen[$candidate] = true;
            if (!$this->userManager->userExists($candidate)) {
                if (count($seen) >= self::MAX_CANDIDATES_SCANNED) {
                    break;
                }
                continue;
            }
            $result[$candidate] = true;
            if (count($result) >= self::MAX_MENTIONS_PER_NOTE || count($seen) >= self::MAX_CANDIDATES_SCANNED) {
                break;
            }
        }
        return array_keys($result);
    }

    /**
     * Delete any stored note_shared/note_mention notification that references
     * $noteId, for every recipient. Called by NoteService::delete() so a
     * recipient who has not yet opened their bell/mobile notification list
     * does not keep an entry pointing at a note that no longer exists (which
     * would otherwise only surface as a dead-end "not found" toast when they
     * eventually click it). Builds a filter notification shaped
     * app='touchpoint', object_type='note', object_id=$noteId and hands it to
     * IManager::markProcessed(), which deletes every STORED notification
     * matching that (app, object) pair, for every recipient — the standard NC
     * pattern used by core apps for exactly this "the referenced object is
     * gone, purge its notifications" case. This is deliberately NOT
     * IManager::dismissNotification(): that method only invokes the optional
     * per-notifier IDismissableNotifier::dismissNotification() hook (a
     * "handle this dismiss action" callback for notifiers that opt into it)
     * and does not touch persisted rows at all; Touchpoint's Notifier does not
     * implement IDismissableNotifier, so calling it here would be a no-op
     * against a real install. Best-effort: exceptions are caught and logged,
     * matching every other public method here — a cleanup failure must never
     * fail the delete that triggered it.
     */
    public function dismissNoteNotifications(int $noteId): void {
        try {
            $notification = $this->notificationManager->createNotification();
            $notification->setApp('touchpoint')
                ->setObject('note', (string) $noteId);
            $this->notificationManager->markProcessed($notification);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Touchpoint: failed to dismiss notifications for deleted note',
                ['exception' => $e, 'noteId' => $noteId]
            );
        }
    }
}
