<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Controller;

use OCA\Touchpoint\Controller\NoteTypeController;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCA\Touchpoint\Service\NoteTypeService;
use OCP\AppFramework\Http\Http;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteTypeControllerTest extends TestCase {

    private NoteTypeController $controller;
    private NoteTypeService $service;
    private IRequest $request;
    private IUserSession $userSession;
    private IL10N $l10n;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(NoteTypeService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('testuser');
        $this->userSession->method('getUser')->willReturn($user);

        $this->controller = new NoteTypeController(
            $this->request,
            $this->service,
            $this->userSession,
            $this->l10n,
            $this->logger,
        );
    }

    public function testIndex(): void {
        $types = [new NoteType(), new NoteType(), new NoteType()];

        $this->service->expects($this->once())
            ->method('findAll')
            ->with('testuser')
            ->willReturn($types);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
        $this->assertCount(3, $result->getData());
    }

    public function testIndexEmpty(): void {
        $this->service->method('findAll')->willReturn([]);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
        $this->assertEmpty($result->getData());
    }

    public function testShow(): void {
        $type = new NoteType();
        $type->setId(1);
        $type->setName('Call');

        $this->service->expects($this->once())
            ->method('find')
            ->with(1, 'testuser')
            ->willReturn($type);

        $result = $this->controller->show(1);
        $this->assertSame(200, $result->getStatus());
    }

    public function testShowNotFound(): void {
        $this->service->method('find')
            ->willThrowException(new NoteTypeNotFoundException('Not found'));

        $result = $this->controller->show(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testCreate(): void {
        $type = new NoteType();
        $type->setId(1);
        $type->setName('Custom');

        $this->service->expects($this->once())
            ->method('create')
            ->with('Custom', 'icon-star', '#ff0000', 'testuser')
            ->willReturn($type);

        $result = $this->controller->create('Custom', 'icon-star', '#ff0000');
        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateWithDefaults(): void {
        $type = new NoteType();
        $type->setId(2);

        // The default icon must be a token the render surfaces actually resolve
        // (src/utils/noteTypeIcon.js); 'icon-note' rendered as no icon, so the
        // controller default is now the renderable 'icon-category-office'.
        $this->service->expects($this->once())
            ->method('create')
            ->with('DefaultType', 'icon-category-office', '#0082c9', 'testuser')
            ->willReturn($type);

        $result = $this->controller->create('DefaultType');
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdate(): void {
        $type = new NoteType();
        $type->setId(1);
        $type->setName('Updated');

        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'testuser', 'Updated', 'icon-phone', '#333333')
            ->willReturn($type);

        $result = $this->controller->update(1, 'Updated', 'icon-phone', '#333333');
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdatePartialNameOnly(): void {
        // A name-only PUT must not 400 (icon/color default to null and are
        // forwarded as such, so the service preserves the existing values).
        $type = new NoteType();
        $type->setId(1);
        $type->setName('Renamed');

        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'testuser', 'Renamed', null, null)
            ->willReturn($type);

        $result = $this->controller->update(1, 'Renamed');
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void {
        $this->service->method('update')
            ->willThrowException(new NoteTypeNotFoundException('Not found'));

        $result = $this->controller->update(999, 'Name', 'icon-note', '#000');
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testDestroy(): void {
        $type = new NoteType();
        $type->setId(1);

        $this->service->expects($this->once())
            ->method('delete')
            ->with(1, 'testuser')
            ->willReturn($type);

        $result = $this->controller->destroy(1);
        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyNotFound(): void {
        $this->service->method('delete')
            ->willThrowException(new NoteTypeNotFoundException('Not found'));

        $result = $this->controller->destroy(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testNotFoundResponseContainsGenericMessage(): void {
        // The raw exception message is not leaked; a generic message is returned.
        $this->service->method('find')
            ->willThrowException(new NoteTypeNotFoundException('Type 999 not found'));

        $result = $this->controller->show(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $this->assertSame('Not found', $result->getData()['message']);
    }

    /**
     * Verify that #[UserRateLimit] is present on create()/update()/destroy(),
     * matching NoteController and AdminNoteTypeController. This prevents the
     * rate limit from being silently dropped during refactoring.
     */
    public function testCreateUpdateDestroyHaveUserRateLimitAttribute(): void {
        foreach (['create', 'update', 'destroy'] as $method) {
            $ref = new \ReflectionMethod(NoteTypeController::class, $method);
            $attrs = $ref->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);
            $this->assertNotEmpty(
                $attrs,
                "NoteTypeController::{$method}() must carry the #[UserRateLimit] attribute",
            );
        }
    }
}
