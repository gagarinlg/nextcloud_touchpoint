<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Controller;

use OCA\CrmNotes\AppInfo\Application;
use OCA\CrmNotes\Service\NoteTypeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private NoteTypeService $noteTypeService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    #[NoAdminRequired]
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        // Seed default note types only for an authenticated user. Don't lean on
        // the service to no-op an empty UID — decide it here so the empty-string
        // path is never passed into the service layer in the first place.
        $user = $this->userSession->getUser();
        if ($user !== null) {
            $this->noteTypeService->seedDefaults($user->getUID());
        }

        Util::addStyle(Application::APP_ID, 'crm_notes-main');
        Util::addScript(Application::APP_ID, 'crm_notes-main', Application::APP_ID);

        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
