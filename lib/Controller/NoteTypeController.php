<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Controller;

use OCA\Touchpoint\AppInfo\Application;
use OCA\Touchpoint\Service\NoteTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class NoteTypeController extends Controller {
    use ErrorHandler;
    use RequiresUser;

    public function __construct(
        IRequest $request,
        private NoteTypeService $noteTypeService,
        private IUserSession $userSession,
        private IL10N $l10n,
        private LoggerInterface $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    public function index(): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteTypeService->findAll($this->getUserId()));
    }

    #[NoAdminRequired]
    public function show(int $id): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteTypeService->find($id, $this->getUserId()));
    }

    #[NoAdminRequired]
    public function usage(int $id): JSONResponse {
        return $this->handleNotFound(fn () => [
            'count' => $this->noteTypeService->countUsage($id, $this->getUserId()),
        ]);
    }

    #[NoAdminRequired]
    public function create(
        string $name,
        string $icon = 'icon-category-office',
        string $color = '#0082c9',
    ): JSONResponse {
        return $this->handleNotFound(
            fn () => $this->noteTypeService->create($name, $icon, $color, $this->getUserId())
        );
    }

    #[NoAdminRequired]
    public function update(
        int $id,
        ?string $name = null,
        ?string $icon = null,
        ?string $color = null,
    ): JSONResponse {
        return $this->handleNotFound(
            fn () => $this->noteTypeService->update($id, $this->getUserId(), $name, $icon, $color)
        );
    }

    #[NoAdminRequired]
    public function destroy(int $id): JSONResponse {
        return $this->handleNotFound(fn () => $this->noteTypeService->delete($id, $this->getUserId()));
    }
}
