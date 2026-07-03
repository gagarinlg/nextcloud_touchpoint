<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Notification;

use OCA\Touchpoint\Service\NoteService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\AlreadyProcessedException;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

class Notifier implements INotifier {

    /**
     * Aliases of NotificationService::SUBJECT_* (the dispatch/producer side,
     * which owns the shared subject vocabulary since it decides what gets
     * sent). Kept here too for backward compatibility with existing call
     * sites — do not redefine the literal strings, reference
     * NotificationService::SUBJECT_* instead if adding a new one.
     */
    public const SUBJECT_NOTE_SHARED = NotificationService::SUBJECT_NOTE_SHARED;
    public const SUBJECT_NOTE_MENTION = NotificationService::SUBJECT_NOTE_MENTION;

    /**
     * Truncation length for the note title embedded in a notification's
     * subject, to bound layout size in the bell dropdown/push text.
     */
    private const MAX_TITLE_LENGTH_IN_SUBJECT = 60;

    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private IUserManager $userManager,
        private NoteService $noteService,
    ) {
    }

    public function getID(): string {
        return 'touchpoint';
    }

    public function getName(): string {
        // Unlike prepare() (which receives an explicit $languageCode per
        // notification), getName() has no language parameter in the
        // INotifier contract — it is rendered for the CURRENTLY logged-in
        // user (e.g. in Settings > Notifications), so resolve their language
        // the same way core does: IFactory::findLanguage() with no app id
        // falls back through the current user's language preference.
        $language = $this->l10nFactory->findLanguage();
        return $this->l10nFactory->get('touchpoint', $language)->t('Touchpoint');
    }

    /**
     * @throws UnknownNotificationException When this notifier does not recognise the app/subject
     * @throws AlreadyProcessedException When the referenced note no longer exists (note_mention), or
     *                                    no longer exists or is no longer accessible to the recipient
     *                                    (note_shared) — signals IManager to garbage-collect this
     *                                    notification instead of leaving a dead-end bell entry. See
     *                                    prepareActorSubject()'s $requireRecipientAccess docblock for
     *                                    why the two subjects differ here.
     *
     * Note on note_mention title disclosure: the stored `noteTitle` subject
     * parameter reflects the recipient's access AT DISPATCH TIME only (see
     * NoteService::notifyMentions()). If that access is later revoked (share
     * removed, public mode turned off) while the notification is still
     * outstanding, every future prepare() call must re-derive whether the
     * title may be shown from the recipient's CURRENT access — never trust
     * the persisted noteTitle parameter by itself for note_mention. See
     * prepareActorSubject()'s handling below.
     */
    public function prepare(INotification $notification, string $languageCode): INotification {
        if ($notification->getApp() !== 'touchpoint') {
            throw new UnknownNotificationException('Unknown app');
        }

        $l = $this->l10nFactory->get('touchpoint', $languageCode);

        switch ($notification->getSubject()) {
            case self::SUBJECT_NOTE_SHARED:
                return $this->prepareActorSubject(
                    $notification,
                    $l,
                    '%1$s shared the note "%2$s" with you',
                    '%s shared a note with you',
                    '{user} shared a note with you',
                    requireRecipientAccess: true,
                );
            case self::SUBJECT_NOTE_MENTION:
                return $this->prepareActorSubject(
                    $notification,
                    $l,
                    '%1$s mentioned you in the note "%2$s"',
                    '%s mentioned you in a note',
                    '{user} mentioned you in a note',
                    requireRecipientAccess: false,
                );
            default:
                throw new UnknownNotificationException('Unknown subject');
        }
    }

    /**
     * Shared rendering logic behind the note_shared / note_mention subjects:
     * resolve the actor's display name, verify the notification should still
     * be shown (throwing AlreadyProcessedException if not, so IManager cleans
     * up the dead notification), then fill in the parsed subject, rich
     * subject, icon and deep link.
     *
     * $requireRecipientAccess controls how "should still be shown" is
     * checked:
     *  - true (note_shared): the recipient must still be able to access the
     *    note (owner, public mode, or an outstanding share) — if the note was
     *    deleted OR the recipient's share was revoked, self-clean.
     *  - false (note_mention): @mention is deliberately usable on a
     *    recipient with NO prior share/ownership relationship to the note
     *    (see docs/API.md's Notifications policy note) — checking full
     *    access here would self-clean the notification the very first time
     *    it is rendered for exactly the intended "loop in a colleague who
     *    doesn't have access yet" use case, before they ever see it. Only
     *    self-clean when the note itself no longer exists.
     *
     * $withTitleFormat/$fallbackFormat drive the plain parsed subject: the
     * title variant is used when a note title is available (the common
     * case), so a user with several same-day notifications can tell them
     * apart without clicking through each one; $fallbackFormat covers the
     * defensive case of an empty title. $richFormat drives the rich subject —
     * it has no title placeholder because there is no rich-object type for a
     * free-text note title in NC core; only the 'user' RichObjectString
     * parameter is threaded through there.
     *
     * Expects subject parameters shaped as
     * array{noteId: int|string, actorUid: string, noteTitle?: string}.
     *
     * @throws AlreadyProcessedException
     */
    private function prepareActorSubject(
        INotification $notification,
        IL10N $l,
        string $withTitleFormat,
        string $fallbackFormat,
        string $richFormat,
        bool $requireRecipientAccess,
    ): INotification {
        $parameters = $notification->getSubjectParameters();
        $actorUid = (string) ($parameters['actorUid'] ?? '');
        $noteId = $parameters['noteId'] ?? $notification->getObjectId();
        $noteTitle = $this->truncateTitle((string) ($parameters['noteTitle'] ?? ''));

        if ($requireRecipientAccess) {
            // note_shared: full existence+access self-cleanup, and the
            // recipient necessarily still has access at this point (the
            // check above just proved it), so the persisted title is safe
            // to show as-is.
            $this->assertNoteStillAccessible($notification->getUser(), $noteId);
            $canShowTitle = true;
        } else {
            // note_mention: existence-only self-cleanup (see class docblock
            // for why access must not gate self-cleanup here). The persisted
            // noteTitle parameter only reflects the recipient's access AT
            // DISPATCH TIME, though — if that access was granted then later
            // revoked while this notification sat unread, every subsequent
            // render must re-check CURRENT access before showing the title,
            // rather than trusting the now-stale persisted parameter.
            $this->assertNoteStillExists($noteId);
            $canShowTitle = $this->noteService->isAccessible((int) $noteId, $notification->getUser());
        }

        $actorName = $this->userManager->getDisplayName($actorUid) ?? $actorUid;

        $parsedSubject = ($canShowTitle && $noteTitle !== '')
            ? $l->t($withTitleFormat, [$actorName, $noteTitle])
            : $l->t($fallbackFormat, [$actorName]);

        $notification->setParsedSubject($parsedSubject)
            ->setRichSubject($richFormat, [
                'user' => $this->userRichParameter($actorUid, $actorName),
            ])
            ->setIcon($this->buildIconUrl())
            ->setLink($this->buildNoteDeepLink($noteId));

        return $notification;
    }

    /**
     * Verify the note referenced by this notification still exists and is
     * still accessible to the recipient (e.g. the owner did not later delete
     * the note or revoke the recipient's share). If not, throw
     * AlreadyProcessedException per the INotifier contract so IManager
     * garbage-collects this now-dead-end notification instead of leaving it
     * in the recipient's bell/mobile list pointing at nothing.
     *
     * Uses NoteService::isAccessible() rather than find(): this runs once
     * per stored note_shared notification on every bell/mobile fetch, and
     * find()'s enrichNote() step (contact/file/sharing-mapper lookups) would
     * be pure overhead here — only the boolean access result is needed, the
     * enriched Note itself is never used.
     *
     * @param int|string $noteId
     * @throws AlreadyProcessedException
     */
    private function assertNoteStillAccessible(string $recipientUserId, int|string $noteId): void {
        if (!$this->noteService->isAccessible((int) $noteId, $recipientUserId)) {
            throw new AlreadyProcessedException();
        }
    }

    /**
     * Verify the note referenced by this notification still exists at all,
     * WITHOUT checking whether the recipient can access it. Used for
     * note_mention only — see prepareActorSubject()'s $requireRecipientAccess
     * docblock for why a mention notification must not self-clean merely
     * because the recipient lacks a share.
     *
     * @throws AlreadyProcessedException
     */
    private function assertNoteStillExists(int|string $noteId): void {
        if (!$this->noteService->noteExists((int) $noteId)) {
            throw new AlreadyProcessedException();
        }
    }

    private function truncateTitle(string $title): string {
        if (mb_strlen($title) <= self::MAX_TITLE_LENGTH_IN_SUBJECT) {
            return $title;
        }
        return mb_substr($title, 0, self::MAX_TITLE_LENGTH_IN_SUBJECT - 1) . '…';
    }

    /**
     * @return array{type: string, id: string, name: string}
     */
    private function userRichParameter(string $uid, string $displayName): array {
        return [
            'type' => 'user',
            'id' => $uid,
            'name' => $displayName,
        ];
    }

    /**
     * Absolute URL to the app's dark-variant icon ('app-dark.svg'). This app
     * currently uses two icon variants across its four registration points,
     * split by the background the icon renders against: the dark variant
     * here and in Settings\AdminSection (both render on a light-background
     * chrome surface — the bell dropdown and the admin settings sidebar,
     * respectively); the light variant ('app.svg') in NoteSearchProvider and
     * ContactsMenu\Provider (both render on a tinted/dark surface — Unified
     * Search results and the contacts hover card, respectively). This is NOT
     * the same variant used by all four call sites — do not assume otherwise
     * when adding a 5th integration point; pick whichever variant matches
     * that surface's background per the rule above.
     */
    private function buildIconUrl(): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->imagePath('touchpoint', 'app-dark.svg')
        );
    }

    /**
     * Build an absolute deep-link URL into the Touchpoint app page that opens
     * the given note, e.g. https://cloud.example.com/apps/touchpoint/#note/123.
     * rawurlencode() (not urlencode()) so the fragment matches App.vue's use
     * of decodeURIComponent(), which does not decode '+' as a space.
     *
     * @param int|string $noteId
     */
    private function buildNoteDeepLink(int|string $noteId): string {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkToRoute('touchpoint.page.index')
            . '#note/' . rawurlencode((string) $noteId)
        );
    }
}
