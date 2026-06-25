<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Controller;

use OCA\CrmNotes\AppInfo\Application;
use OCA\CrmNotes\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

class SettingsController extends Controller {
    use ErrorHandler;
    use RequiresUser;

    public function __construct(
        IRequest $request,
        private SettingsService $settingsService,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    private function isAdmin(): bool {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }
        return $this->groupManager->isAdmin($user->getUID());
    }

    #[NoAdminRequired]
    public function get(): JSONResponse {
        return $this->handleNotFound(function () {
            $userId = $this->getUserId();
            return [
                'notesPublic'  => $this->settingsService->isNotesPublic(),
                'isAdmin'      => $this->isAdmin(),
                'shareTargets' => $this->settingsService->getUserShareTargets($userId),
            ];
        });
    }

    #[NoAdminRequired]
    public function save(bool $notesPublic = false, ?array $shareTargets = null): JSONResponse {
        return $this->handleNotFound(function () use ($notesPublic, $shareTargets) {
            $userId = $this->getUserId();

            // Only admins may change the system-wide public flag
            if ($this->isAdmin()) {
                $this->settingsService->setNotesPublic($notesPublic);
            }

            if ($shareTargets !== null) {
                $this->settingsService->setUserShareTargets($userId, $shareTargets);
            }

            return [
                'notesPublic'  => $this->settingsService->isNotesPublic(),
                'isAdmin'      => $this->isAdmin(),
                'shareTargets' => $this->settingsService->getUserShareTargets($userId),
            ];
        });
    }

    #[NoAdminRequired]
    public function searchPrincipals(string $q = ''): JSONResponse {
        // Require a non-trivial query length: a single-character query would let
        // the autocomplete be walked to enumerate the directory even on
        // instances where enumeration is allowed. The service additionally
        // honours the core enumeration-privacy settings, scoped to the caller.
        if (mb_strlen(trim($q)) < 2) {
            return new JSONResponse([]);
        }
        return new JSONResponse(
            $this->settingsService->searchPrincipals($q, 10, $this->getUserId())
        );
    }
}
