<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Controller;

use OCA\Touchpoint\Controller\PageController;
use OCA\Touchpoint\Service\NoteTypeService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

// The Contacts app's OCA-API event class is provided either by a real Contacts
// install or by tests/stubs.php (an optional-dependency stub), so PageController's
// class_exists() guard sees it as available and the "Contacts enabled" branch is
// exercised without pulling in the actual Contacts app.
class PageControllerTest extends TestCase {

    private PageController $controller;
    private IRequest $request;
    private IUserSession $userSession;
    private NoteTypeService $noteTypeService;
    private IAppManager $appManager;
    private IEventDispatcher $eventDispatcher;
    private IInitialState $initialState;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->noteTypeService = $this->createMock(NoteTypeService::class);
        $this->appManager = $this->createMock(IAppManager::class);
        $this->eventDispatcher = $this->createMock(IEventDispatcher::class);
        $this->initialState = $this->createMock(IInitialState::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = $this->makeController();
    }

    private function makeController(?IUserSession $userSession = null): PageController {
        return new PageController(
            $this->request,
            $userSession ?? $this->userSession,
            $this->noteTypeService,
            $this->appManager,
            $this->eventDispatcher,
            $this->initialState,
        );
    }

    public function testIndexReturnsTemplateResponse(): void {
        $this->noteTypeService->expects($this->once())
            ->method('seedDefaults')
            ->with('testuser');

        $result = $this->controller->index();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testIndexCallsSeedDefaults(): void {
        $this->noteTypeService->expects($this->once())
            ->method('seedDefaults')
            ->with('testuser');

        $this->controller->index();
    }

    public function testIndexWithNullUser(): void {
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = $this->makeController($userSession);

        // With no authenticated user the controller must NOT pass an empty UID
        // into the service — it skips seeding entirely rather than relying on a
        // service-side guard.
        $this->noteTypeService->expects($this->never())
            ->method('seedDefaults');

        $result = $controller->index();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }

    public function testIndexDispatchesContactsOcaEventWhenContactsEnabled(): void {
        // When the Contacts app is enabled for the user (and its OCA event class
        // is available), index() must dispatch the LoadContactsOcaApiEvent so the
        // contacts-oca bundle is loaded onto our page, AND expose the
        // contactsAppEnabled flag as true to the frontend.
        $this->appManager->method('isEnabledForUser')
            ->with('contacts', $this->anything())
            ->willReturn(true);

        $this->eventDispatcher->expects($this->once())
            ->method('dispatchTyped')
            ->with($this->isInstanceOf(\OCA\Contacts\Event\LoadContactsOcaApiEvent::class));

        $this->initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('contactsAppEnabled', true);

        $this->controller->index();
    }

    public function testIndexDoesNotDispatchContactsOcaEventWhenContactsDisabled(): void {
        // When the Contacts app is NOT enabled for the user, index() must neither
        // dispatch the OCA event nor advertise the embedded-card integration as
        // available (contactsAppEnabled === false).
        $this->appManager->method('isEnabledForUser')
            ->with('contacts', $this->anything())
            ->willReturn(false);

        $this->eventDispatcher->expects($this->never())
            ->method('dispatchTyped');

        $this->initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('contactsAppEnabled', false);

        $this->controller->index();
    }

    public function testIndexReportsContactsDisabledForAnonymousUser(): void {
        // No authenticated user → the Contacts integration cannot be enabled and
        // the OCA event must never be dispatched.
        $userSession = $this->createMock(IUserSession::class);
        $userSession->method('getUser')->willReturn(null);

        $controller = $this->makeController($userSession);

        $this->eventDispatcher->expects($this->never())
            ->method('dispatchTyped');

        $this->initialState->expects($this->once())
            ->method('provideInitialState')
            ->with('contactsAppEnabled', false);

        $controller->index();
    }
}
