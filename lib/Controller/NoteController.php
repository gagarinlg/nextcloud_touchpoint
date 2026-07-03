<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Controller;

use OCA\Touchpoint\AppInfo\Application;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Service\NoteService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class NoteController extends Controller {
    use ErrorHandler;
    use RequiresUser;

    public function __construct(
        IRequest $request,
        private NoteService $noteService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Read and validate the optional 'sort' query parameter, mapping anything
     * other than the explicit 'oldest' keyword to the default newest-first.
     * Centralised so index() and byContact() validate identically and a bogus
     * value can never reach the service/mapper as a raw SQL direction.
     */
    private function getSortParam(): string {
        $sort = (string) $this->request->getParam('sort', NoteMapper::SORT_NEWEST);
        return $sort === NoteMapper::SORT_OLDEST
            ? NoteMapper::SORT_OLDEST
            : NoteMapper::SORT_NEWEST;
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        return $this->handleNotFound(function () {
            $limit  = $this->request->getParam('limit');
            $offset = $this->request->getParam('offset');

            // Default to 50 to prevent returning tens of thousands of notes at once.
            // Clamp the client-supplied values so a caller cannot defeat the cap
            // (e.g. ?limit=1000000) or pass a negative offset: limit is bounded to
            // [1, 200] and offset to [0, ∞). Without this clamp the requested window
            // is used verbatim as setMaxResults/setFirstResult, and in private mode
            // findAll re-sorts the whole owned+shared id set in PHP on every call —
            // so an oversized/negative limit is a cheap CPU/memory amplifier.
            $limitInt  = max(1, min((int)($limit ?? 50), 200));
            $offsetInt = max(0, (int)($offset ?? 0));

            return $this->noteService->findAll($this->getUserId(), $limitInt, $offsetInt, $this->getSortParam());
        });
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function show(int $id): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteService->find($id, $this->getUserId()));
    }

    #[NoAdminRequired]
    public function byContact(string $contactUid): JSONResponse {
        return $this->handleNotFound(function () use ($contactUid) {
            // Surface the same bounded paging as index(): findByContact() pushes
            // a LIMIT down to the id/sort-key window instead of materialising and
            // PHP-sorting a contact's entire (possibly over-linked) note history
            // on every panel open. The service clamps the window itself, but pass
            // null through untouched so it can apply its own defaults.
            $limit  = $this->request->getParam('limit');
            $offset = $this->request->getParam('offset');
            $limitInt  = $limit !== null && $limit !== '' ? (int)$limit : null;
            $offsetInt = $offset !== null && $offset !== '' ? (int)$offset : null;

            return $this->noteService->findByContact(
                $contactUid,
                $this->getUserId(),
                $limitInt,
                $offsetInt,
                $this->getSortParam(),
            );
        });
    }

    #[NoAdminRequired]
    #[UserRateLimit(limit: 30, period: 60)]
    public function search(): JSONResponse {
        // Pre-trim q here so the mb_strlen check operates on the true character
        // count. NoteService::search() also trims unconditionally for callers
        // that bypass this controller (e.g. NoteSearchProvider via
        // NoteService::search() directly).
        // Coerce defensively: a crafted `?q[]=x` makes getParam() return an
        // array, and `(string) $array` would raise an E_WARNING and search the
        // literal "Array". A non-string q is meaningless — treat it as empty.
        $rawQ = $this->request->getParam('q', '');
        $q = trim(is_string($rawQ) ? $rawQ : '');
        if (mb_strlen($q) > NoteMapper::MAX_SEARCH_TERM_LENGTH) {
            // Return 400: consistent with the app's assertMaxLength validation
            // pattern. Silent truncation is avoided because the caller cannot
            // tell whether their full query was used.
            return new JSONResponse(
                ['message' => $this->l10n->t('Search query must not exceed 500 characters.')],
                Http::STATUS_BAD_REQUEST
            );
        }
        return $this->handleNotFound(function () use ($q) {
            $limit    = $this->request->getParam('limit');
            $offset   = $this->request->getParam('offset');
            // Defense-in-depth clamp. The service also clamps unconditionally.
            // The service clamp is authoritative for callers that bypass this
            // controller (e.g., NoteSearchProvider via NoteService::search()).
            $limitInt  = max(1, min((int)($limit ?? 50), 200));
            $offsetInt = max(0, (int)($offset ?? 0));
            return $this->noteService->search(
                $this->getUserId(),
                $q,
                $limitInt,
                $offsetInt,
                $this->getSortParam()
            );
        });
    }

    #[NoAdminRequired]
    // Bounds the note-save rate, which indirectly bounds notification fan-out
    // (note_shared / note_mention dispatch on create/update): without this,
    // any writer could repeatedly save a note naming up to 50 @mentioned users
    // as fast as HTTP allows, spamming every one of them with a fresh
    // notification per call. Mirrors search()'s existing rate limit.
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        // Default '' (like the other optional params) so a request that omits
        // contactUid entirely does NOT feed null into a non-nullable string
        // parameter during AppFramework dispatch — that TypeError is raised
        // outside handleNotFound() and escapes as an opaque 500. An absent
        // primary contact is a normal client case (NoteService::create() only
        // links a junction row when contactUid !== ''), so it flows through the
        // ordinary validation path instead.
        string $contactUid = '',
        // Nullable with a null default so an omitted noteTypeId arrives here as
        // null instead of letting the AppFramework feed null into a non-nullable
        // int parameter (which raises a TypeError caught only by ErrorHandler's
        // generic \Throwable arm -> opaque 500). NoteService::create() rejects a
        // null/<=0 value via assertNoteTypeVisible, so a missing required type
        // produces a clean 400 like every other invalid-input path.
        ?int $noteTypeId = null,
        string $title = '',
        // addressbook_id is currently unused for authorization or lookups; the
        // contacts manager only exposes a non-numeric address-book key, so no
        // real numeric id is available. Defaults to 0 and may be omitted by
        // clients. See NoteService::create() for the stored-column note.
        int $addressbookId = 0,
        ?string $content = null,
        bool $isPinned = false,
        array $contactUids = [],
        ?array $sharing = null,
    ): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteService->create(
            $contactUid,
            $addressbookId,
            $noteTypeId,
            $title,
            $content,
            $this->getUserId(),
            $isPinned,
            $contactUids,
            $sharing,
        ));
    }

    #[NoAdminRequired]
    // See create()'s #[UserRateLimit] comment: bounds note-save rate, which
    // indirectly bounds note_shared/note_mention notification fan-out.
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $title = null,
        ?string $content = null,
        ?int $noteTypeId = null,
        ?bool $isPinned = null,
        ?array $contactUids = null,
        ?array $sharing = null,
    ): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteService->update(
            $id,
            $this->getUserId(),
            $title,
            $content,
            $noteTypeId,
            $isPinned,
            $contactUids,
            $sharing,
        ));
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteService->delete($id, $this->getUserId()));
    }

    #[NoAdminRequired]
    public function addFile(int $noteId): JSONResponse {
        return $this->handleNotFound(function () use ($noteId) {
            $filePath = (string) $this->request->getParam('filePath', '');
            $fileId = $this->request->getParam('fileId');
            $fileId = ($fileId !== null && $fileId !== '') ? (int) $fileId : 0;

            return $this->noteService->addFile(
                $noteId,
                $fileId,
                $filePath,
                $this->getUserId(),
            );
        });
    }

    #[NoAdminRequired]
    public function removeFile(int $noteId, int $noteFileId): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteService->removeFile(
            $noteFileId,
            $noteId,
            $this->getUserId(),
        ));
    }
}
