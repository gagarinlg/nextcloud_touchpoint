<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Service;

use DateTime;
use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteContact;
use OCA\Touchpoint\Db\NoteContactMapper;
use OCA\Touchpoint\Db\NoteFile;
use OCA\Touchpoint\Db\NoteFileMapper;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Db\NoteSharing;
use OCA\Touchpoint\Db\NoteSharingMapper;
use OCA\Touchpoint\Notification\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DBException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class NoteService {

    /** touchpoint.title column length (VARCHAR(255)). */
    private const MAX_TITLE_LENGTH = 255;
    /** touchpoint.contact_uid column length (VARCHAR(255)). */
    private const MAX_CONTACT_UID_LENGTH = 255;
    /**
     * Hard cap on note content length. touchpoint.content is declared Types::TEXT
     * (migration), which on MySQL/MariaDB tops out at 65,535 BYTES. mb_strlen()
     * counts characters, and a single multibyte character can be up to 4 bytes in
     * utf8mb4, so the worst-case byte cost of N characters is 4*N. Capping at
     * 16,000 characters keeps the worst case (64,000 bytes) comfortably under the
     * 65,535-byte TEXT limit, so an over-length body yields a clean 400 here
     * instead of a silent truncation (non-strict MySQL) or an opaque DB-truncation
     * 500 (strict mode) — exactly the guard already applied to title/contact_uid.
     */
    private const MAX_CONTENT_LENGTH = 16000;
    /**
     * Hard server-side cap on the number of notes findByContact() will return
     * in one page. Mirrors the clamp NoteController::index() applies to findAll
     * so neither endpoint can be coerced into materialising, enriching and
     * PHP-sorting an unbounded note set per call.
     */
    public const MAX_CONTACT_PAGE_SIZE = 200;
    /** Default page size for findByContact() when the caller does not specify one. */
    public const DEFAULT_CONTACT_PAGE_SIZE = 50;
    /**
     * Maximum number of distinct share targets accepted in a single
     * create()/update() `sharing` payload. Mirrors
     * SettingsService::MAX_SHARE_TARGETS, but is enforced by REJECTING the
     * request (NoteValidationException -> HTTP 400) rather than silently
     * truncating: unlike a user's own saved defaults (harmless to trim
     * silently), a note's sharing list is a security-relevant ACL, and a
     * silent truncation here would leave the caller believing principals were
     * shared with when they were not. Checked BEFORE the per-target
     * principalExists() DB lookup loop runs, so the lookup cost itself is
     * bounded — a payload listing thousands of distinct (real or fabricated)
     * group/user ids cannot force thousands of sequential DB round-trips
     * inside one HTTP request.
     */
    private const MAX_SHARE_TARGETS_PER_REQUEST = 100;

    public function __construct(
        private NoteMapper $mapper,
        private NoteContactMapper $noteContactMapper,
        private NoteFileMapper $noteFileMapper,
        private NoteSharingMapper $noteSharingMapper,
        private SettingsService $settingsService,
        private NoteTypeService $noteTypeService,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
        private NotificationService $notificationService,
    ) {
    }

    /**
     * Reject over-length string input before it hits a column limit, so callers
     * get a clean 400 instead of an opaque 500 from a DB truncation error.
     *
     * @throws NoteValidationException
     */
    private function assertMaxLength(string $value, int $max, string $field): void {
        if (mb_strlen($value) > $max) {
            throw new NoteValidationException(
                $field . ' must not exceed ' . $max . ' characters'
            );
        }
    }

    /**
     * Validate that the caller may attach a note to the given note type. The
     * type must be one the caller can see (own type or a global default);
     * arbitrary/foreign ids are rejected so they never enter note_type_id.
     *
     * A null or non-positive id means the required field was omitted/invalid;
     * reject it as a clean validation error rather than letting a null TypeError
     * (or a doomed lookup) escape as an opaque 500. Returns the validated id so
     * callers obtain a guaranteed non-null int for the entity setter.
     *
     * @throws NoteValidationException
     */
    private function assertNoteTypeVisible(?int $noteTypeId, string $userId): int {
        if ($noteTypeId === null || $noteTypeId <= 0) {
            throw new NoteValidationException('A note type is required');
        }
        try {
            $this->noteTypeService->find($noteTypeId, $userId);
        } catch (NoteTypeNotFoundException) {
            throw new NoteValidationException('Invalid note type');
        }
        return $noteTypeId;
    }

    /**
     * Reject a `sharing` payload whose RAW entry count already exceeds
     * MAX_SHARE_TARGETS_PER_REQUEST, before any per-target principalExists()
     * DB lookup runs — see that constant's docblock. Called both up front in
     * create()/update() (so an over-long list never leaves a half-completed
     * note/share state behind) and defensively again inside
     * sanitiseShareTargets().
     *
     * @param array<array{type?: string, id?: string, canEdit?: bool}>|null $targets
     * @throws NoteValidationException
     */
    private function assertShareTargetCount(?array $targets): void {
        if ($targets !== null && count($targets) > self::MAX_SHARE_TARGETS_PER_REQUEST) {
            throw new NoteValidationException(
                'Too many share targets: must not exceed ' . self::MAX_SHARE_TARGETS_PER_REQUEST
            );
        }
    }

    /**
     * Drop share targets whose type is not 'user'/'group' or whose principal
     * does not exist, so phantom/typo'd entries never enter the permission
     * table. Returns the filtered, well-formed target list.
     *
     * @param array<array{type?: string, id?: string, canEdit?: bool}> $targets
     * @return array<array{type: string, id: string, canEdit: bool}>
     * @throws NoteValidationException
     */
    private function sanitiseShareTargets(array $targets): array {
        $this->assertShareTargetCount($targets);
        $clean = [];
        $seen  = [];
        foreach ($targets as $target) {
            $type = (string)($target['type'] ?? '');
            $id   = (string)($target['id'] ?? '');
            if (($type !== 'user' && $type !== 'group') || $id === '') {
                continue;
            }
            // Deduplicate by (type, id) so the same principal is persisted once.
            // touchpoint_note_sharing has a UNIQUE index on
            // (note_id, shared_with_type, shared_with_id); a payload listing the
            // same principal twice would otherwise blow up syncSharing()'s second
            // insert with a unique-constraint violation. Mirrors
            // SettingsService::setUserShareTargets().
            $key = $type . ':' . $id;
            if (isset($seen[$key])) {
                continue;
            }
            if (!$this->settingsService->principalExists($type, $id)) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = [
                'type' => $type,
                'id' => $id,
                'canEdit' => !empty($target['canEdit']),
            ];
        }
        return $clean;
    }

    /**
     * Verify that the caller may modify (edit/delete/attach) the given note.
     * Allowed when notes are globally public, the caller owns the note, or the
     * caller has an explicit write share (can_edit = true).
     *
     * @throws NoteForbiddenException
     */
    private function assertCanWrite(Note $note, string $userId): void {
        if ($this->settingsService->isNotesPublic()) {
            return;
        }
        if ($note->getUserId() === $userId) {
            return;
        }
        $groupIds = $this->settingsService->getUserGroupIds($userId);
        $writable = $this->noteSharingMapper->findWritableNoteIds($userId, $groupIds);
        if (!in_array($note->getId(), $writable, true)) {
            throw new NoteForbiddenException('You are not allowed to modify this note');
        }
    }

    /**
     * Decide whether audit/identity fields should be exposed for this note to
     * the given caller. Owners (and everyone in public mode) see them; plain
     * share recipients do not.
     */
    private function applyAuditVisibility(Note $note, ?string $callerUserId, ?array $callerGroupIds = null): void {
        $expose = $this->settingsService->isNotesPublic()
            || ($callerUserId !== null && $note->getUserId() === $callerUserId);
        $note->setExposeAudit($expose);
        // When audit fields are hidden, the entity reduces the exposed sharing
        // list to the viewer's own entry — give it the viewer's identity and
        // group memberships to make that filter possible.
        if (!$expose && $callerUserId !== null) {
            $groupIds = $callerGroupIds ?? $this->settingsService->getUserGroupIds($callerUserId);
            $note->setViewer($callerUserId, $groupIds);
        }
    }

    private function enrichNote(Note $note, ?string $callerUserId = null): Note {
        $contacts = $this->noteContactMapper->findByNoteId($note->getId());
        $note->setContacts(array_map(fn (NoteContact $nc) => $nc->jsonSerialize(), $contacts));
        // Resolve the caller's group memberships once and hand them to
        // applyAuditVisibility(), mirroring enrichNotes(), so a non-owner viewer
        // does not trigger a second group-membership lookup.
        $callerGroupIds = $callerUserId !== null
            ? $this->settingsService->getUserGroupIds($callerUserId)
            : null;
        $this->applyAuditVisibility($note, $callerUserId, $callerGroupIds);
        $files = $this->noteFileMapper->findByNoteId($note->getId());
        $note->setFiles($this->serializeFiles($files, $note->getExposeAudit()));
        $sharing = $this->noteSharingMapper->findByNoteId($note->getId());
        $note->setSharing(array_map(fn (NoteSharing $ns) => $ns->jsonSerialize(), $sharing));
        return $note;
    }

    /**
     * Serialize a note's attached files with recipient-aware filtering. The
     * owner (and everyone in public mode) sees the full record including the
     * owner-relative filePath and internal fileId. A plain read-only/write share
     * recipient must NOT learn the owner's private folder structure or internal
     * file IDs — that is the same class of identity/structure information the
     * audit-visibility code withholds — so for non-owners we expose only a
     * non-identifying basename label and drop filePath/fileId entirely.
     *
     * @param NoteFile[] $files
     * @return array<array<string, mixed>>
     */
    private function serializeFiles(array $files, bool $exposeOwnerData): array {
        if ($exposeOwnerData) {
            return array_map(fn (NoteFile $nf) => $nf->jsonSerialize(), $files);
        }
        return array_map(function (NoteFile $nf): array {
            $path = $nf->getFilePath();
            // basename() of an owner-relative path leaks only the file name, not
            // the directory layout. The attachment is not openable by recipients
            // (it lives in the owner's namespace), so a label is all they need.
            $name = $path !== '' ? basename($path) : '';
            return [
                'id' => $nf->getId(),
                'noteId' => $nf->getNoteId(),
                'name' => $name,
            ];
        }, $files);
    }

    /**
     * Enrich a batch of notes using a fixed number of queries instead of 3N.
     *
     * @param Note[] $notes
     * @return Note[]
     */
    private function enrichNotes(array $notes, ?string $callerUserId = null): array {
        if (empty($notes)) {
            return [];
        }
        $ids = array_map(fn (Note $n) => $n->getId(), $notes);
        $contactMap = $this->noteContactMapper->findByNoteIds($ids);
        $fileMap    = $this->noteFileMapper->findByNoteIds($ids);
        $sharingMap = $this->noteSharingMapper->findByNoteIds($ids);

        // Resolve the caller's group memberships once for the whole batch, so the
        // per-note sharing-visibility filter doesn't re-query group membership N
        // times.
        $callerGroupIds = $callerUserId !== null
            ? $this->settingsService->getUserGroupIds($callerUserId)
            : [];

        foreach ($notes as $note) {
            $id = $note->getId();
            $note->setContacts(array_map(
                fn (NoteContact $nc) => $nc->jsonSerialize(),
                $contactMap[$id] ?? []
            ));
            // Resolve audit visibility first so file serialization can apply the
            // same recipient-aware filtering (owner paths / internal IDs hidden
            // from plain share recipients).
            $this->applyAuditVisibility($note, $callerUserId, $callerGroupIds);
            $note->setFiles($this->serializeFiles($fileMap[$id] ?? [], $note->getExposeAudit()));
            $note->setSharing(array_map(
                fn (NoteSharing $ns) => $ns->jsonSerialize(),
                $sharingMap[$id] ?? []
            ));
        }
        return $notes;
    }

    /**
     * Normalise an arbitrary caller-supplied sort keyword to one of the two
     * supported values, defaulting to newest-first. Keeps validation in one
     * place so every entry point (findAll, findByContact) is consistent.
     */
    private function normaliseSort(string $sort): string {
        return $sort === NoteMapper::SORT_OLDEST
            ? NoteMapper::SORT_OLDEST
            : NoteMapper::SORT_NEWEST;
    }

    /**
     * @param string $sort 'newest' (created_at DESC, default) or 'oldest' (created_at ASC)
     * @return Note[]
     */
    public function findAll(string $userId, ?int $limit = null, ?int $offset = null, string $sort = NoteMapper::SORT_NEWEST): array {
        $sort = $this->normaliseSort($sort);
        if ($this->settingsService->isNotesPublic()) {
            $notes = $this->mapper->findAllPublic($limit, $offset, $sort);
            return $this->enrichNotes($notes, $userId);
        }

        // Ordering and paging are pushed all the way down to the database: the
        // owned set (user_id = :uid) is unioned with the explicitly-shared id
        // set in a single WHERE, ordered newest-first, and the LIMIT/OFFSET
        // window is applied in SQL. Only the requested page of rows is ever
        // materialised — a heavy user no longer loads, unions and PHP-sorts
        // their entire id/sort-key set on every loadMoreNotes call.
        $groupIds  = $this->settingsService->getUserGroupIds($userId);
        $sharedIds = $this->noteSharingMapper->findAccessibleNoteIds($userId, $groupIds);

        $page = $this->mapper->findAccessiblePage($userId, $sharedIds, $limit, $offset, $sort);

        return $this->enrichNotes($page, $userId);
    }

    /**
     * @throws NoteNotFoundException
     */
    public function find(int $id, string $userId): Note {
        try {
            // Allow access if public, owned, or shared with user
            if ($this->settingsService->isNotesPublic()) {
                $note = $this->mapper->findByIdPublic($id);
            } else {
                // Try own note first, then check sharing
                try {
                    $note = $this->mapper->findById($id, $userId);
                } catch (DoesNotExistException) {
                    $groupIds  = $this->settingsService->getUserGroupIds($userId);
                    $accessible = $this->noteSharingMapper->findAccessibleNoteIds($userId, $groupIds);
                    if (!in_array($id, $accessible, true)) {
                        throw new NoteNotFoundException("Note $id not found or not accessible");
                    }
                    $note = $this->mapper->findByIdPublic($id);
                }
            }
            return $this->enrichNote($note, $userId);
        } catch (DoesNotExistException) {
            // A public-mode lookup (or the post-share fallback) for an unknown
            // id throws DoesNotExistException; surface it as a clean 404 rather
            // than letting it escape to the generic 500 handler.
            throw new NoteNotFoundException("Note $id not found");
        } catch (MultipleObjectsReturnedException) {
            throw new NoteNotFoundException('Note not found');
        }
    }

    /**
     * Whether note $id still exists at all, REGARDLESS of the caller's
     * access rights to it (no user/sharing scoping, unlike find()). Used by
     * Notifier's @mention self-cleanup check: an @mention notification must
     * only be garbage-collected once the note is actually gone, never merely
     * because the mentioned recipient lacks a share — @mention is
     * deliberately usable on a user with no prior relationship to the note
     * (see docs/API.md's Notifications policy note), so gating its
     * self-cleanup on the same access check as note_shared would delete the
     * notification before the mentioned user ever has a chance to see it.
     */
    public function noteExists(int $id): bool {
        try {
            $this->mapper->findByIdPublic($id);
            return true;
        } catch (DoesNotExistException) {
            return false;
        } catch (MultipleObjectsReturnedException) {
            return true;
        }
    }

    /**
     * Whether $userId currently has read access to note $id (owner, public
     * mode, or an outstanding user/group share) — a lighter-weight
     * equivalent of find() succeeding, without the enrichNote() overhead
     * (which pulls in the contact junction, file, and sharing mappers plus
     * group-membership lookups for audit visibility, none of which this
     * boolean check needs). Used by Notifier::assertNoteStillAccessible(),
     * which is invoked once per stored note_shared notification on every
     * bell/mobile fetch and previously discarded a fully-enriched Note just
     * to check "did this throw".
     *
     * Mirrors find()'s access resolution exactly (public mode / ownership /
     * outstanding share), loading only the bare note row plus, for the
     * non-owner path, the same accessible-id lookup find() already performs
     * — no enrichment step is added on top of that.
     */
    public function isAccessible(int $id, string $userId): bool {
        try {
            if ($this->settingsService->isNotesPublic()) {
                $this->mapper->findByIdPublic($id);
                return true;
            }
            try {
                $this->mapper->findById($id, $userId);
                return true;
            } catch (DoesNotExistException) {
                return $this->hasOutstandingShare($id, $userId);
            }
        } catch (DoesNotExistException) {
            return false;
        } catch (MultipleObjectsReturnedException) {
            return false;
        }
    }

    /**
     * Whether $userId has an outstanding (user- or group-based) share on note
     * $id — the common tail of isAccessible() and userHasAccessToNote() once
     * their respective owner/public-mode fast paths have already ruled out a
     * cheaper positive answer. Not a full access check on its own: callers
     * must handle ownership and public-mode themselves first.
     */
    private function hasOutstandingShare(int $id, string $userId): bool {
        $groupIds = $this->settingsService->getUserGroupIds($userId);
        $accessible = $this->noteSharingMapper->findAccessibleNoteIds($userId, $groupIds);
        return in_array($id, $accessible, true);
    }

    /**
     * Return the notes attached to a contact that the caller may see, ordered
     * pinned-first then by created_at in the caller-chosen direction
     * (newest-first by default), as a bounded page.
     *
     * Like findAll(), pagination is pushed down to id + sort-key rows rather
     * than materialising every linked note: we collect the candidate id set,
     * filter it to the ids the caller is actually allowed to see, fetch only
     * the (is_pinned, created_at) tuples for that visible set, order them once,
     * slice the requested window, and only then load and enrich that single
     * page of full rows. A contact with a very large (or maliciously
     * over-linked) note history therefore no longer forces a full load + PHP
     * re-sort on every panel open.
     *
     * @param int|null $limit  page size; clamped to [1, MAX_CONTACT_PAGE_SIZE]
     * @param int|null $offset window offset; clamped to [0, ∞)
     * @param string $sort 'newest' (created_at DESC, default) or 'oldest' (created_at ASC)
     * @return Note[]
     */
    public function findByContact(string $contactUid, string $userId, ?int $limit = null, ?int $offset = null, string $sort = NoteMapper::SORT_NEWEST): array {
        $limit  = max(1, min($limit ?? self::DEFAULT_CONTACT_PAGE_SIZE, self::MAX_CONTACT_PAGE_SIZE));
        $offset = max(0, $offset ?? 0);
        $oldestFirst = $this->normaliseSort($sort) === NoteMapper::SORT_OLDEST;

        $isPublic = $this->settingsService->isNotesPublic();

        // Collect candidate note IDs from the junction table.
        $noteContacts = $this->noteContactMapper->findByContactUid($contactUid);
        $noteIds = array_map(fn (NoteContact $nc) => $nc->getNoteId(), $noteContacts);

        // Also collect IDs by the primary-contact column (contact_uid) so a note
        // whose primary contact is this contact is found even if it has no
        // matching junction row. Do NOT owner-scope this lookup: a note created
        // by another user, shared with the caller, and linked to this contact
        // ONLY via the contact_uid column (no junction row) would otherwise never enter
        // $noteIds, so the shared-notes filtering below could never recover it.
        // Collect all candidate IDs regardless of owner and let the
        // owned/shared/public filtering decide visibility.
        foreach ($this->mapper->findIdsByContact($contactUid) as $legacyId) {
            $noteIds[] = $legacyId;
        }
        $noteIds = array_values(array_unique($noteIds));

        if (empty($noteIds)) {
            return [];
        }

        // Reduce the candidate set to the ids the caller may actually see,
        // BEFORE loading any full rows. In public mode every candidate is
        // visible; otherwise it must be owned by the caller or shared with them.
        if ($isPublic) {
            // Public mode: every candidate is visible and none was owner-scoped
            // above, so fetch all sort keys in one unscoped pass.
            $sortKeys = $this->mapper->findSortKeysByIds($noteIds, null);
        } else {
            // The owner-scoped pass already returned this user's notes WITH their
            // sort keys; reuse those rows instead of re-querying them. Only the
            // shared-but-not-owned remainder still needs its sort keys fetched,
            // so the heavy id->sort-key query never covers the owned set twice.
            $ownedKeys = $this->mapper->findSortKeysByIds($noteIds, $userId);

            $groupIds  = $this->settingsService->getUserGroupIds($userId);
            $sharedIds = $this->noteSharingMapper->findAccessibleNoteIds($userId, $groupIds);
            $sharedInContact = array_intersect($noteIds, $sharedIds);
            // Shared ids that are not already in the owned set; only these need a
            // second sort-key lookup.
            $sharedOnlyIds = array_values(array_diff($sharedInContact, array_keys($ownedKeys)));

            $sortKeys = $ownedKeys;
            if (!empty($sharedOnlyIds)) {
                $sortKeys += $this->mapper->findSortKeysByIds($sharedOnlyIds, null);
            }
        }

        if (empty($sortKeys)) {
            return [];
        }

        // Order: pinned first, then created_at in the caller-chosen direction
        // (newest- or oldest-first), with the unique id as the final tiebreaker
        // in the same direction. This is the canonical contact-panel ordering, so
        // the panel orders the same way the all-notes views do.
        $ordered = array_keys($sortKeys);
        usort($ordered, function (int $a, int $b) use ($sortKeys, $oldestFirst) {
            $aPinned = $sortKeys[$a]['is_pinned'] ?? false;
            $bPinned = $sortKeys[$b]['is_pinned'] ?? false;
            if ($aPinned !== $bPinned) {
                return $bPinned ? 1 : -1;
            }
            $aKey = $sortKeys[$a]['created_at'] ?? '';
            $bKey = $sortKeys[$b]['created_at'] ?? '';
            if ($aKey === $bKey) {
                return $oldestFirst ? ($a <=> $b) : ($b <=> $a);
            }
            return $oldestFirst ? ($aKey <=> $bKey) : ($bKey <=> $aKey);
        });

        // Slice the requested window, then load and enrich only that page.
        $ordered = array_slice($ordered, $offset, $limit);
        if (empty($ordered)) {
            return [];
        }

        $rows = $this->mapper->findByIds($ordered, null);
        $byId = [];
        foreach ($rows as $row) {
            $byId[$row->getId()] = $row;
        }
        $page = [];
        foreach ($ordered as $id) {
            if (isset($byId[$id])) {
                $page[] = $byId[$id];
            }
        }

        return $this->enrichNotes($page, $userId);
    }

    /**
     * Search notes accessible to $userId whose title or content matches $term
     * (case-insensitive iLike). Returns [] for blank/whitespace-only terms and
     * for public mode (where keyword search would expose other users' notes to
     * any authenticated searcher without per-user ownership context).
     *
     * Limit is clamped to [1, 200] here; the service clamp is authoritative for
     * all callers (HTTP controller and NoteSearchProvider). The controller also
     * clamps as defense-in-depth but explicitly documents that this clamp governs.
     *
     * Access scoping composes the same tested helpers as findAll() verbatim.
     * $userId serves double duty as the access-scope principal and the enrichment
     * viewer; do not introduce a separate $viewerUserId without a security review,
     * as enrichNotes() uses it to determine which sharing and audit fields are
     * visible.
     *
     * @return Note[]
     */
    public function search(string $userId, string $term, ?int $limit = null, ?int $offset = null, string $sort = NoteMapper::SORT_NEWEST): array {
        // Trim first: whitespace-only input (q='   ') must be caught server-side
        // independent of any frontend trimming.
        $term = trim($term);

        // Blank term: no useful query, return immediately without touching the DB
        // or resolving group memberships.
        if ($term === '') {
            return [];
        }

        // Enforce the 500-character maximum on all callers, including the Unified
        // Search provider (NoteSearchProvider), which bypasses NoteController and
        // its HTTP-level 400 guard. An oversized term produces a LIKE '%…50000-char…%'
        // against a TEXT column — a full-table scan with pathological DB memory use.
        // The HTTP rate-limit does not protect this path.
        if (mb_strlen($term) > NoteMapper::MAX_SEARCH_TERM_LENGTH) {
            return [];
        }

        // Public mode makes all notes globally readable by any authenticated user.
        // Returning all notes via keyword search would expose other users' notes
        // to any authenticated searcher without the expected per-user ownership
        // context of both the Unified Search UI and the direct API.
        // Return empty. If product explicitly requires public-mode search,
        // remove this guard and document the decision in ARCHITECTURE.md.
        if ($this->settingsService->isNotesPublic()) {
            return [];
        }

        // Authoritative clamp — callers that bypass the HTTP controller (e.g.
        // NoteSearchProvider) rely solely on this enforcement.
        $limit  = max(1, min($limit ?? 50, 200));
        $offset = max(0, $offset ?? 0);

        $sort = $this->normaliseSort($sort);

        $groupIds  = $this->settingsService->getUserGroupIds($userId);
        $sharedIds = $this->noteSharingMapper->findAccessibleNoteIds($userId, $groupIds);

        $page = $this->mapper->searchAccessiblePage($userId, $sharedIds, $term, $limit, $offset, $sort);

        return $this->enrichNotes($page, $userId);
    }

    /**
     * Note on $addressbookId: this is currently a dead field. The Contacts
     * manager only exposes a non-numeric address-book key (e.g. 'contacts'),
     * so no real numeric address-book id is available client-side; the value
     * stored in touchpoint.addressbook_id and touchpoint_note_contacts.addressbook_id
     * is effectively always 0. It is not used for any authorization check or
     * lookup. Do NOT build a feature that trusts this column until a real
     * numeric id is plumbed through (which would require a new migration).
     */
    public function create(
        string $contactUid,
        int $addressbookId,
        ?int $noteTypeId,
        string $title,
        ?string $content,
        string $userId,
        bool $isPinned = false,
        array $contactUids = [],
        ?array $sharing = null,
    ): Note {
        // A missing/blank title is a client error, not a server error: reject it
        // here so an omitted title yields a clean 400 (like over-length input)
        // rather than persisting an empty-titled note. Mirrors NoteModal.vue's
        // required-title guard on the frontend.
        if (trim($title) === '') {
            throw new NoteValidationException('Title must not be empty');
        }
        $this->assertMaxLength($title, self::MAX_TITLE_LENGTH, 'Title');
        if ($content !== null) {
            $this->assertMaxLength($content, self::MAX_CONTENT_LENGTH, 'Content');
        }
        $this->assertMaxLength($contactUid, self::MAX_CONTACT_UID_LENGTH, 'Contact');
        // Validate every additional junction UID up front, before any row is
        // inserted, so an over-length entry yields a clean 400 (like the primary
        // contact_uid) instead of an opaque DB truncation 500 — and never leaves
        // an orphan note behind from a half-completed create.
        foreach ($contactUids as $uid) {
            $this->assertMaxLength((string)$uid, self::MAX_CONTACT_UID_LENGTH, 'Contact');
        }
        // Validate the share-target count up front too, before any row is
        // inserted — same "no half-completed create" rationale as above. See
        // MAX_SHARE_TARGETS_PER_REQUEST's docblock.
        $this->assertShareTargetCount($sharing);
        $noteTypeId = $this->assertNoteTypeVisible($noteTypeId, $userId);

        $now = new DateTime();

        $note = new Note();
        // touchpoint.contact_uid is NOT dead/redundant duplication of the junction
        // table: it is the canonical pointer to the note's PRIMARY contact. The
        // touchpoint_note_contacts junction set is unordered and has no "primary" marker,
        // so the scalar contact_uid is the only place that records which of the
        // linked contacts is the primary one. The frontend relies on it (NoteItem
        // renders this contact's name, links to it, and excludes it from the
        // "also linked" list), so newly-created notes must keep writing it. The
        // junction row(s) below additionally model the full many-to-many set.
        $note->setContactUid($contactUid);
        $note->setAddressbookId($addressbookId);
        $note->setNoteTypeId($noteTypeId);
        $note->setTitle($title);
        $note->setContent($content ?? '');
        $note->setUserId($userId);
        $note->setIsPinned($isPinned);
        $note->setCreatedAt($now);
        $note->setUpdatedAt($now);
        $note->setCreatedBy($userId);
        $note->setUpdatedBy($userId);

        $note = $this->mapper->insert($note);

        // Link to contacts via junction table. touchpoint_note_contacts has no
        // rows yet for a brand-new note, so the primary contact_uid must be
        // folded into the set here (update()'s equivalent step instead starts
        // from a full deleteByNoteId(), since it's resyncing an existing set).
        $allUids = $contactUids;
        if ($contactUid !== '' && !in_array($contactUid, $allUids, true)) {
            $allUids[] = $contactUid;
        }
        $this->syncNoteContacts($note->getId(), $addressbookId, $allUids);

        $this->applyInitialSharingAndNotify($note, $userId, $sharing);
        $this->notifyMentions($note, $userId, $note->getContent(), $note->getTitle());

        return $this->enrichNote($note, $userId);
    }

    /**
     * Replace the full junction-table contact set for $noteId with $uids
     * (deduplicated; blanks filtered). Used identically by create() (seeding
     * the initial set, including the primary contact) and update() (resyncing
     * an edited set) — the (note_id, contact_uid) unique index is enforced at
     * the DB level, so a duplicate insert attempt is swallowed rather than
     * treated as an error.
     *
     * @param list<string> $uids
     */
    private function syncNoteContacts(int $noteId, int $addressbookId, array $uids): void {
        $uids = array_values(array_unique(array_filter($uids, fn ($u) => $u !== '')));
        foreach ($uids as $uid) {
            $nc = new NoteContact();
            $nc->setNoteId($noteId);
            $nc->setContactUid($uid);
            $nc->setAddressbookId($addressbookId);
            try {
                $this->noteContactMapper->insert($nc);
            } catch (DBException $e) {
                if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                    throw $e;
                }
                // Duplicate link — ignore, the contact is already attached.
            }
        }
    }

    /**
     * create()'s sharing step: apply explicit sharing if provided, otherwise
     * fall back to the user's configured defaults, then notify every
     * resulting target (group targets expanded to their current members) —
     * on create every target is by definition newly added. Best-effort:
     * NotificationService swallows and logs its own failures, so a
     * notification error can never fail the create.
     */
    private function applyInitialSharingAndNotify(Note $note, string $userId, ?array $sharing): void {
        $targets = $this->sanitiseShareTargets(
            $sharing ?? $this->settingsService->getUserShareTargets($userId)
        );
        if (!empty($targets)) {
            $this->noteSharingMapper->syncSharing($note->getId(), $targets);
        }
        $this->notifyShareTargets($note->getId(), $userId, $targets, $note->getTitle());
    }

    /**
     * Notify the individual users behind a set of {type, id} share targets
     * (groups expanded to members) that $actorUserId shared note $noteId with
     * them. The actor themselves is never notified — enforced both here
     * (defense in depth) and inside NotificationService.
     *
     * @param array<array{type: string, id: string, canEdit?: bool}> $targets
     */
    private function notifyShareTargets(int $noteId, string $actorUserId, array $targets, string $noteTitle = ''): void {
        if (empty($targets)) {
            return;
        }
        $userIds = $this->notificationService->expandShareTargetsToUserIds($targets, $actorUserId);
        foreach ($userIds as $targetUserId) {
            $this->notificationService->sendShareNotification($noteId, $actorUserId, $targetUserId, $noteTitle);
        }
    }

    /**
     * Scan note content for @userId mentions and notify each one that resolves
     * to a real, existing user. The actor is never notified about mentioning
     * themselves — enforced both here and inside NotificationService.
     *
     * @mention is deliberately usable on a user with no prior share/ownership
     * relationship to the note (see docs/API.md's Notifications policy note)
     * — but the note's TITLE must not be disclosed to such a user via the
     * notification's subject/push-preview text, since they cannot yet open
     * the note to see it themselves. The title is included only for a
     * mentioned user who already has access (owner, public mode, or an
     * outstanding share); a user without access instead gets the title-less
     * fallback wording (Notifier::prepareActorSubject()'s $fallbackFormat).
     */
    private function notifyMentions(Note $note, string $actorUserId, ?string $content, string $noteTitle = ''): void {
        $mentioned = $this->notificationService->extractMentionedUserIds($content, $actorUserId);
        foreach ($mentioned as $mentionedUserId) {
            $titleForRecipient = $this->userHasAccessToNote($note, $mentionedUserId) ? $noteTitle : '';
            $this->notificationService->sendMentionNotification($note->getId(), $actorUserId, $mentionedUserId, $titleForRecipient);
        }
    }

    /**
     * Whether $userId currently has read access to $note (owner, public
     * mode, or an outstanding user/group share) — a lighter-weight
     * equivalent of find() succeeding, without the enrichNote() overhead,
     * since this is evaluated once per mentioned user (up to
     * NotificationService::MAX_MENTIONS_PER_NOTE times per save). Used to
     * decide whether it is safe to disclose the note's title to a mentioned
     * user who has not yet been granted access.
     *
     * Query cost: when notes are not public and $userId is not the owner,
     * this issues up to 2 DB queries (getUserGroupIds() + findAccessibleNoteIds()),
     * so a single create()/update() call can add up to
     * 2 * NotificationService::MAX_MENTIONS_PER_NOTE synchronous round-trips
     * in the worst case (many distinct non-owner mentions). Bounded by that
     * cap; not memoized since 50 is already a hard ceiling per save.
     */
    private function userHasAccessToNote(Note $note, string $userId): bool {
        if ($this->settingsService->isNotesPublic() || $note->getUserId() === $userId) {
            return true;
        }
        return $this->hasOutstandingShare($note->getId(), $userId);
    }

    /**
     * @throws NoteNotFoundException
     */
    public function update(
        int $id,
        string $userId,
        ?string $title = null,
        ?string $content = null,
        ?int $noteTypeId = null,
        ?bool $isPinned = null,
        ?array $contactUids = null,
        ?array $sharing = null,
    ): Note {
        $note = $this->find($id, $userId);
        $this->assertCanWrite($note, $userId);

        // Validate all length-constrained input up front, before any mutation,
        // so an over-length value yields a clean 400 without leaving the note
        // half-updated.
        if ($title !== null) {
            $this->assertMaxLength($title, self::MAX_TITLE_LENGTH, 'Title');
        }
        if ($content !== null) {
            $this->assertMaxLength($content, self::MAX_CONTENT_LENGTH, 'Content');
        }
        if ($contactUids !== null) {
            foreach ($contactUids as $uid) {
                $this->assertMaxLength((string)$uid, self::MAX_CONTACT_UID_LENGTH, 'Contact');
            }
        }
        // Only validated up front when the caller can actually manage sharing
        // (owner, or public mode) — mirrors the gate around sanitiseShareTargets()
        // below: a non-owner's sharing field is silently ignored regardless of
        // its shape, so it must not fail an otherwise-valid content/title edit.
        if ($sharing !== null && $this->canManageSharing($note, $userId)) {
            $this->assertShareTargetCount($sharing);
        }

        // Snapshot the content BEFORE it is overwritten, so mention-notification
        // dispatch below can tell an actual edit from a byte-identical resave.
        // Without this diff, any write-access caller could re-POST the same
        // content over and over and re-trigger a fresh note_mention
        // notification to up to 50 mentioned users on every call — a
        // notification-spam primitive against users who never asked for it.
        $previousContent = $note->getContent();

        if ($title !== null) {
            $note->setTitle($title);
        }
        if ($content !== null) {
            $note->setContent($content);
        }
        if ($noteTypeId !== null) {
            // Validate the chosen note type against the note OWNER's visibility,
            // not the editing caller's. A write-share recipient must not be able
            // to set the note to one of their own private types, which the owner
            // (and other recipients) cannot resolve — that would leave the type
            // badge blank for everyone but the editor.
            $this->assertNoteTypeVisible($noteTypeId, $note->getUserId());
            $note->setNoteTypeId($noteTypeId);
        }
        if ($isPinned !== null) {
            $note->setIsPinned($isPinned);
        }
        $note->setUpdatedAt(new DateTime());
        $note->setUpdatedBy($userId);

        $note = $this->mapper->update($note);

        // Sync contacts if provided — unlike create(), this resyncs an
        // existing set, so the junction table is cleared first.
        if ($contactUids !== null) {
            $this->noteContactMapper->deleteByNoteId($note->getId());
            $this->syncNoteContacts($note->getId(), $note->getAddressbookId(), $contactUids);
        }

        // Sync sharing if provided — but managing a note's sharing/ACL is an
        // owner-only operation. A write-share recipient may edit content, yet
        // must not be able to grant the note to new principals, escalate their
        // own share to can_edit, or strip the owner's other shares. Silently
        // ignore the sharing field for non-owners (unless notes are public).
        if ($sharing !== null && $this->canManageSharing($note, $userId)) {
            $this->syncSharingAndNotifyAdded($note, $userId, $sharing);
        }

        // Only rescan/notify when the content actually changed — a resubmit of
        // byte-identical content (e.g. a save that only touches the title,
        // pinned flag, or contact list, since the frontend always includes
        // `content` in its payload) must not re-notify every mentioned user.
        if ($content !== null && $content !== $previousContent) {
            $this->notifyMentions($note, $userId, $content, $note->getTitle());
        }

        return $this->enrichNote($note, $userId);
    }

    /**
     * update()'s sharing step: replace the note's sharing set with $sharing,
     * then notify only the targets newly added in this call (a recipient
     * re-saved into the same share list, or one whose canEdit flag merely
     * changed, must not be re-notified). Only called when the caller is
     * already confirmed to be allowed to manage sharing.
     */
    private function syncSharingAndNotifyAdded(Note $note, string $userId, array $sharing): void {
        // Snapshot the pre-update sharing set BEFORE syncSharing() replaces
        // it, so newly-added targets can be diffed out below and only those
        // get a notification.
        $before = $this->noteSharingMapper->findByNoteId($note->getId());
        $newTargets = $this->sanitiseShareTargets($sharing);

        $this->noteSharingMapper->syncSharing($note->getId(), $newTargets);

        $addedTargets = $this->diffNewShareTargets($before, $newTargets);
        $this->notifyShareTargets($note->getId(), $userId, $addedTargets, $note->getTitle());
    }

    /**
     * Return the subset of $newTargets that were NOT already present in
     * $before, keyed by (type, id) — a target already shared is not "newly
     * added" even if its canEdit flag changed, so recipients are not
     * re-notified every time the owner tweaks edit permissions.
     *
     * @param NoteSharing[] $before
     * @param array<array{type: string, id: string, canEdit?: bool}> $newTargets
     * @return array<array{type: string, id: string, canEdit?: bool}>
     */
    private function diffNewShareTargets(array $before, array $newTargets): array {
        $existing = [];
        foreach ($before as $ns) {
            $existing[$ns->getSharedWithType() . ':' . $ns->getSharedWithId()] = true;
        }
        return array_values(array_filter(
            $newTargets,
            fn (array $t) => !isset($existing[$t['type'] . ':' . $t['id']])
        ));
    }

    /**
     * Whether the caller may rewrite a note's sharing/ACL. Restricted to the
     * note owner (or anyone, in global public mode).
     */
    private function canManageSharing(Note $note, string $userId): bool {
        return $this->settingsService->isNotesPublic()
            || $note->getUserId() === $userId;
    }

    /**
     * @throws NoteNotFoundException
     */
    public function delete(int $id, string $userId): Note {
        $note = $this->find($id, $userId);
        $this->assertCanWrite($note, $userId);
        $this->noteContactMapper->deleteByNoteId($note->getId());
        $this->noteFileMapper->deleteByNoteId($note->getId());
        $this->noteSharingMapper->deleteByNoteId($note->getId());
        $this->mapper->delete($note);
        // Best-effort: any note_shared/note_mention notification still
        // pointing at this now-deleted note is dismissed for every recipient,
        // rather than left as a dead-end bell/mobile entry. Swallows and logs
        // its own failures (see NotificationService), so this can never fail
        // the delete that triggered it.
        $this->notificationService->dismissNoteNotifications($note->getId());
        return $note;
    }

    /**
     * Attach a file to a note. Attachments are resolved against, and stored
     * relative to, the note OWNER's storage — never the (possibly different)
     * caller's. A stored file_path is only meaningful inside one user's
     * namespace, so a write-share recipient attaching a file living solely in
     * their own storage would persist a path the owner and other recipients
     * cannot open. We therefore restrict attaching to the note owner (or, in
     * global public mode, any caller, resolving against the owner's folder).
     * The supplied file ID/path is validated against that owner's files via
     * IRootFolder, so a client cannot attach a file the owner does not have
     * access to (IDOR protection).
     *
     * @throws NoteNotFoundException
     * @throws NoteForbiddenException
     */
    public function addFile(int $noteId, int $fileId, string $filePath, string $userId): Note {
        $note = $this->find($noteId, $userId);
        $this->assertCanWrite($note, $userId);

        // Attachments only make sense within the note owner's storage. A plain
        // write-share recipient may edit content, but must not attach a file
        // from their own namespace that the owner cannot resolve. Require
        // ownership (public mode keeps the legacy any-caller behaviour but still
        // resolves against the owner's folder below).
        if (!$this->settingsService->isNotesPublic() && $note->getUserId() !== $userId) {
            throw new NoteForbiddenException('Only the note owner can attach files');
        }

        // Resolve and validate the file against the note OWNER's storage.
        // Reject anything the owner cannot access.
        $resolved = $this->resolveOwnedFile($fileId, $filePath, $note->getUserId());
        if ($resolved === null) {
            throw new NoteForbiddenException('File is not accessible to this user');
        }
        [$realFileId, $realPath] = $resolved;

        $nf = new NoteFile();
        $nf->setNoteId($noteId);
        $nf->setFilePath($realPath);
        // The path is resolved in the owner's namespace, so the attachment row
        // belongs to the owner — not the (possibly recipient) caller.
        $nf->setUserId($note->getUserId());
        if ($realFileId > 0) {
            $nf->setFileId($realFileId);
        }

        try {
            $this->noteFileMapper->insert($nf);
        } catch (DBException $e) {
            // A concurrent attach of the same (note_id, file_path) hits the
            // crm_nf_path_unique index — treat it as already-attached.
            if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                throw $e;
            }
        }

        return $this->enrichNote($note, $userId);
    }

    /**
     * Resolve a file by ID (preferred) or path within the caller's user folder.
     *
     * @return array{int, string}|null  [fileId, path] relative to user folder, or null if inaccessible
     */
    private function resolveOwnedFile(int $fileId, string $filePath, string $userId): ?array {
        if ($userId === '') {
            return null;
        }
        try {
            $userFolder = $this->rootFolder->getUserFolder($userId);
        } catch (\Throwable $e) {
            $this->logger->warning('Touchpoint: could not open user folder for file validation', ['exception' => $e]);
            return null;
        }

        // Prefer resolving by file ID — this is what the client claims to attach.
        if ($fileId > 0) {
            $nodes = $userFolder->getById($fileId);
            if (!empty($nodes)) {
                $node = $nodes[0];
                return [$node->getId(), $userFolder->getRelativePath($node->getPath()) ?? $node->getPath()];
            }
            // Claimed ID is not accessible to this user.
            return null;
        }

        // Fall back to resolving by path.
        if ($filePath !== '') {
            try {
                $node = $userFolder->get($filePath);
                return [$node->getId(), $userFolder->getRelativePath($node->getPath()) ?? $filePath];
            } catch (NotFoundException) {
                return null;
            }
        }

        return null;
    }

    /**
     * Detach a file from a note. File attachments live in the note OWNER's
     * storage and can only be ADDED by the owner (see addFile()), so detaching
     * is symmetrically owner-only: a write-share recipient who can never attach
     * a file must not be able to permanently destroy the owner's attachment rows
     * either. Mirroring addFile()'s owner check keeps the permission model
     * consistent and prevents silent data loss for the owner. In global public
     * mode every caller is effectively an owner, matching addFile().
     *
     * @throws NoteNotFoundException
     * @throws NoteForbiddenException
     */
    public function removeFile(int $noteFileId, int $noteId, string $userId): Note {
        $note = $this->find($noteId, $userId);
        $this->assertCanWrite($note, $userId);

        // Detaching is owner-only (mirrors addFile): a write-share recipient may
        // edit content but must not delete the owner's attachments.
        if (!$this->settingsService->isNotesPublic() && $note->getUserId() !== $userId) {
            throw new NoteForbiddenException('Only the note owner can remove files');
        }

        $files = $this->noteFileMapper->findByNoteId($noteId);
        $deleted = false;
        foreach ($files as $file) {
            if ($file->getId() === $noteFileId) {
                $this->noteFileMapper->delete($file);
                $deleted = true;
                break;
            }
        }
        // Signal a stale/foreign noteFileId instead of silently returning 200 —
        // otherwise removing a file that does not belong to the note looks like a
        // successful no-op and masks client bugs.
        if (!$deleted) {
            throw new NoteNotFoundException("File $noteFileId not found on note $noteId");
        }
        return $this->enrichNote($note, $userId);
    }
}
