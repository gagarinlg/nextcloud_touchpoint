<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Service;

use DateTime;
use OCA\CrmNotes\Db\Note;
use OCA\CrmNotes\Db\NoteContact;
use OCA\CrmNotes\Db\NoteContactMapper;
use OCA\CrmNotes\Db\NoteFile;
use OCA\CrmNotes\Db\NoteFileMapper;
use OCA\CrmNotes\Db\NoteMapper;
use OCA\CrmNotes\Db\NoteSharing;
use OCA\CrmNotes\Db\NoteSharingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DBException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

class NoteService {

    /** crm_notes.title column length (VARCHAR(255)). */
    private const MAX_TITLE_LENGTH = 255;
    /** crm_notes.contact_uid column length (VARCHAR(255)). */
    private const MAX_CONTACT_UID_LENGTH = 255;
    /**
     * Hard server-side cap on the number of notes findByContact() will return
     * in one page. Mirrors the clamp NoteController::index() applies to findAll
     * so neither endpoint can be coerced into materialising, enriching and
     * PHP-sorting an unbounded note set per call.
     */
    public const MAX_CONTACT_PAGE_SIZE = 200;
    /** Default page size for findByContact() when the caller does not specify one. */
    public const DEFAULT_CONTACT_PAGE_SIZE = 50;

    public function __construct(
        private NoteMapper $mapper,
        private NoteContactMapper $noteContactMapper,
        private NoteFileMapper $noteFileMapper,
        private NoteSharingMapper $noteSharingMapper,
        private SettingsService $settingsService,
        private NoteTypeService $noteTypeService,
        private IRootFolder $rootFolder,
        private LoggerInterface $logger,
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
     * @throws NoteValidationException
     */
    private function assertNoteTypeVisible(int $noteTypeId, string $userId): void {
        try {
            $this->noteTypeService->find($noteTypeId, $userId);
        } catch (NoteTypeNotFoundException $e) {
            throw new NoteValidationException('Invalid note type');
        }
    }

    /**
     * Drop share targets whose type is not 'user'/'group' or whose principal
     * does not exist, so phantom/typo'd entries never enter the permission
     * table. Returns the filtered, well-formed target list.
     *
     * @param array<array{type?: string, id?: string, canEdit?: bool}> $targets
     * @return array<array{type: string, id: string, canEdit: bool}>
     */
    private function sanitiseShareTargets(array $targets): array {
        $clean = [];
        $seen  = [];
        foreach ($targets as $target) {
            $type = (string)($target['type'] ?? '');
            $id   = (string)($target['id'] ?? '');
            if (($type !== 'user' && $type !== 'group') || $id === '') {
                continue;
            }
            // Deduplicate by (type, id) so the same principal is persisted once.
            // crm_note_sharing has a UNIQUE index on
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
        } catch (MultipleObjectsReturnedException $e) {
            throw new NoteNotFoundException('Note not found');
        }
    }

    /**
     * Return the notes attached to a contact that the caller may see, ordered
     * pinned-first then most-recently-updated, as a bounded page.
     *
     * Like findAll(), pagination is pushed down to id + sort-key rows rather
     * than materialising every linked note: we collect the candidate id set,
     * filter it to the ids the caller is actually allowed to see, fetch only
     * the (is_pinned, updated_at, created_at) tuples for that visible set,
     * order them once, slice the requested window, and only then load and
     * enrich that single page of full rows. A contact with a very large (or
     * maliciously over-linked) note history therefore no longer forces a full
     * load + PHP re-sort on every panel open.
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

        // Also collect IDs from the legacy contact_uid column (direct link).
        // Do NOT owner-scope this lookup: a note created by another user, shared
        // with the caller, and linked to this contact ONLY via the legacy
        // contact_uid column (no junction row) would otherwise never enter
        // $noteIds, so the shared-notes filtering below could never recover it.
        // Collect all candidate IDs regardless of owner and let the
        // owned/shared/public filtering decide visibility.
        $legacyNotes = $this->mapper->findByContact($contactUid, null);
        foreach ($legacyNotes as $note) {
            $noteIds[] = $note->getId();
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
        // in the same direction. Mirrors NoteMapper::findByContact() so the
        // contact panel orders the same way the all-notes views do.
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
     * Note on $addressbookId: this is currently a dead field. The Contacts
     * manager only exposes a non-numeric address-book key (e.g. 'contacts'),
     * so no real numeric address-book id is available client-side; the value
     * stored in crm_notes.addressbook_id and crm_note_contacts.addressbook_id
     * is effectively always 0. It is not used for any authorization check or
     * lookup. Do NOT build a feature that trusts this column until a real
     * numeric id is plumbed through (which would require a new migration).
     */
    public function create(
        string $contactUid,
        int $addressbookId,
        int $noteTypeId,
        string $title,
        ?string $content,
        string $userId,
        bool $isPinned = false,
        array $contactUids = [],
        ?array $sharing = null,
    ): Note {
        $this->assertMaxLength($title, self::MAX_TITLE_LENGTH, 'Title');
        $this->assertMaxLength($contactUid, self::MAX_CONTACT_UID_LENGTH, 'Contact');
        // Validate every additional junction UID up front, before any row is
        // inserted, so an over-length entry yields a clean 400 (like the primary
        // contact_uid) instead of an opaque DB truncation 500 — and never leaves
        // an orphan note behind from a half-completed create.
        foreach ($contactUids as $uid) {
            $this->assertMaxLength((string)$uid, self::MAX_CONTACT_UID_LENGTH, 'Contact');
        }
        $this->assertNoteTypeVisible($noteTypeId, $userId);

        $now = new DateTime();

        $note = new Note();
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

        // Link to contacts via junction table
        $allUids = $contactUids;
        if ($contactUid !== '' && !in_array($contactUid, $allUids, true)) {
            $allUids[] = $contactUid;
        }
        // Deduplicate to avoid violating the (note_id, contact_uid) unique index
        $allUids = array_values(array_unique(array_filter($allUids, fn ($u) => $u !== '')));
        foreach ($allUids as $uid) {
            $nc = new NoteContact();
            $nc->setNoteId($note->getId());
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

        // Apply explicit sharing if provided, otherwise use user defaults.
        // Validate principals so no bogus type or phantom id is persisted.
        $targets = $this->sanitiseShareTargets(
            $sharing ?? $this->settingsService->getUserShareTargets($userId)
        );
        if (!empty($targets)) {
            $this->noteSharingMapper->syncSharing($note->getId(), $targets);
        }

        return $this->enrichNote($note, $userId);
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
        if ($contactUids !== null) {
            foreach ($contactUids as $uid) {
                $this->assertMaxLength((string)$uid, self::MAX_CONTACT_UID_LENGTH, 'Contact');
            }
        }

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

        // Sync contacts if provided
        if ($contactUids !== null) {
            $uids = array_values(array_unique(array_filter($contactUids, fn ($u) => $u !== '')));
            $this->noteContactMapper->deleteByNoteId($note->getId());
            foreach ($uids as $uid) {
                $nc = new NoteContact();
                $nc->setNoteId($note->getId());
                $nc->setContactUid($uid);
                $nc->setAddressbookId($note->getAddressbookId());
                try {
                    $this->noteContactMapper->insert($nc);
                } catch (DBException $e) {
                    if ($e->getReason() !== DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
                        throw $e;
                    }
                }
            }
        }

        // Sync sharing if provided — but managing a note's sharing/ACL is an
        // owner-only operation. A write-share recipient may edit content, yet
        // must not be able to grant the note to new principals, escalate their
        // own share to can_edit, or strip the owner's other shares. Silently
        // ignore the sharing field for non-owners (unless notes are public).
        if ($sharing !== null && $this->canManageSharing($note, $userId)) {
            $this->noteSharingMapper->syncSharing(
                $note->getId(),
                $this->sanitiseShareTargets($sharing)
            );
        }

        return $this->enrichNote($note, $userId);
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
            $this->logger->warning('CRM Notes: could not open user folder for file validation', ['exception' => $e]);
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
