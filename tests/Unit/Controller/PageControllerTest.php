<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Controller;

use OCA\CrmNotes\Controller\PageController;
use OCA\CrmNotes\Service\NoteTypeService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class PageControllerTest extends TestCase {

    private PageController $controller;
    private IRequest $request;
    private IUserSession $userSession;
    private NoteTypeService $noteTypeService;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->noteTypeService = $this->createMock(NoteTypeService::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new PageController(
            $this->request,
            $this->userSession,
            $this->noteTypeService,
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

        $controller = new PageController(
            $this->request,
            $userSession,
            $this->noteTypeService,
        );

        // With no authenticated user the controller must NOT pass an empty UID
        // into the service — it skips seeding entirely rather than relying on a
        // service-side guard.
        $this->noteTypeService->expects($this->never())
            ->method('seedDefaults');

        $result = $controller->index();
        $this->assertInstanceOf(TemplateResponse::class, $result);
    }
}
