<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Controller;

use OCA\Touchpoint\AppInfo\Application;
use OCA\Touchpoint\Service\NoteTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AdminNoteTypeController extends Controller {
    use ErrorHandler;

    public function __construct(
        IRequest $request,
        private NoteTypeService $noteTypeService,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function index(): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteTypeService->findGlobalDefaults());
    }

    /**
     * Number of notes system-wide using a global note type — lets the admin UI
     * check before opening a delete confirmation, mirroring the per-user
     * GET /api/note-types/{id}/usage endpoint.
     */
    public function usage(int $id): JSONResponse {
        return $this->handleNotFound(
            fn () => ['count' => $this->noteTypeService->countGlobalUsage($id)]
        );
    }

    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        string $name,
        string $icon = 'icon-category-office',
        string $color = '#0082c9',
    ): JSONResponse {
        return $this->handleNotFound(
            fn () => $this->noteTypeService->createGlobal($name, $icon, $color)
        );
    }

    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?string $icon = null,
        ?string $color = null,
    ): JSONResponse {
        return $this->handleNotFound(
            fn () => $this->noteTypeService->updateGlobal($id, $name, $icon, $color)
        );
    }

    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(int $id): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteTypeService->deleteGlobal($id));
    }
}
