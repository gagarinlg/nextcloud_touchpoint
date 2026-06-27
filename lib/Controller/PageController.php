<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Controller;

use OCA\Touchpoint\AppInfo\Application;
use OCA\Touchpoint\Service\NoteTypeService;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Util;

class PageController extends Controller {

    public function __construct(
        IRequest $request,
        private IUserSession $userSession,
        private NoteTypeService $noteTypeService,
        private IAppManager $appManager,
        private IEventDispatcher $eventDispatcher,
        private IInitialState $initialState,
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

        // When the Contacts app is installed, dispatch its OCA-API event so it
        // adds the `contacts-oca` bundle (which exposes window.OCA.Contacts) to
        // THIS page — that lets us embed a live contact card next to a contact's
        // notes via OCA.Contacts.mountContactDetails(). Guard on the app being
        // enabled for the user AND the event class existing before referencing
        // it, so the page still works when Contacts is absent or disabled.
        $contactsAppEnabled = $user !== null
            && $this->appManager->isEnabledForUser('contacts', $user)
            && class_exists(\OCA\Contacts\Event\LoadContactsOcaApiEvent::class);
        if ($contactsAppEnabled) {
            $this->eventDispatcher->dispatchTyped(new \OCA\Contacts\Event\LoadContactsOcaApiEvent());
        }
        // Tell the frontend whether the embedded-card integration is available so
        // it never offers a "Show contact details" control that cannot work.
        $this->initialState->provideInitialState('contactsAppEnabled', $contactsAppEnabled);

        Util::addStyle(Application::APP_ID, 'touchpoint-main');
        Util::addScript(Application::APP_ID, 'touchpoint-main', Application::APP_ID);

        return new TemplateResponse(Application::APP_ID, 'main');
    }
}
