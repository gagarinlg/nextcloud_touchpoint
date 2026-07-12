<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Controller;

use OCA\Touchpoint\Controller\AdminNoteTypeController;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteTypeInUseException;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\NoteValidationException;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class AdminNoteTypeControllerTest extends TestCase {

    private AdminNoteTypeController $controller;
    private NoteTypeService $service;
    private IRequest $request;
    private IL10N $l10n;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(NoteTypeService::class);
        $this->l10n = $this->createMock(IL10N::class);
        $this->l10n->method('t')->willReturnArgument(0);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->controller = new AdminNoteTypeController(
            $this->request,
            $this->service,
            $this->l10n,
            $this->logger,
        );
    }

    public function testIndex(): void {
        $types = [new NoteType(), new NoteType()];

        $this->service->expects($this->once())
            ->method('findGlobalDefaults')
            ->willReturn($types);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
        $this->assertCount(2, $result->getData());
    }

    public function testIndexEmpty(): void {
        $this->service->method('findGlobalDefaults')->willReturn([]);

        $result = $this->controller->index();
        $this->assertSame(200, $result->getStatus());
        $this->assertEmpty($result->getData());
    }

    public function testUsage(): void {
        $this->service->expects($this->once())
            ->method('countGlobalUsage')
            ->with(1)
            ->willReturn(4);

        $result = $this->controller->usage(1);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame(['count' => 4], $result->getData());
    }

    public function testUsageZero(): void {
        $this->service->method('countGlobalUsage')->with(1)->willReturn(0);

        $result = $this->controller->usage(1);
        $this->assertSame(200, $result->getStatus());
        $this->assertSame(['count' => 0], $result->getData());
    }

    public function testCreate(): void {
        $type = new NoteType();
        $type->setId(1);
        $type->setName('Custom');

        $this->service->expects($this->once())
            ->method('createGlobal')
            ->with('Custom', 'icon-star', '#ff0000')
            ->willReturn($type);

        $result = $this->controller->create('Custom', 'icon-star', '#ff0000');
        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateWithDefaults(): void {
        $type = new NoteType();
        $type->setId(2);

        $this->service->expects($this->once())
            ->method('createGlobal')
            ->with('DefaultType', 'icon-category-office', '#0082c9')
            ->willReturn($type);

        $result = $this->controller->create('DefaultType');
        $this->assertSame(200, $result->getStatus());
    }

    public function testCreateValidationError(): void {
        $this->service->method('createGlobal')
            ->willThrowException(new NoteValidationException('Name too long'));

        $result = $this->controller->create(str_repeat('a', 200));
        $this->assertSame(Http::STATUS_BAD_REQUEST, $result->getStatus());
    }

    public function testUpdate(): void {
        $type = new NoteType();
        $type->setId(1);
        $type->setName('Updated');

        $this->service->expects($this->once())
            ->method('updateGlobal')
            ->with(1, 'Updated', 'icon-phone', '#333333')
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
            ->method('updateGlobal')
            ->with(1, 'Renamed', null, null)
            ->willReturn($type);

        $result = $this->controller->update(1, 'Renamed');
        $this->assertSame(200, $result->getStatus());
    }

    public function testUpdateNotFound(): void {
        $this->service->method('updateGlobal')
            ->willThrowException(new NoteTypeNotFoundException('Not found'));

        $result = $this->controller->update(999, 'Name', 'icon-note', '#000');
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testDestroy(): void {
        $type = new NoteType();
        $type->setId(1);

        $this->service->expects($this->once())
            ->method('deleteGlobal')
            ->with(1)
            ->willReturn($type);

        $result = $this->controller->destroy(1);
        $this->assertSame(200, $result->getStatus());
    }

    public function testDestroyNotFound(): void {
        $this->service->method('deleteGlobal')
            ->willThrowException(new NoteTypeNotFoundException('Not found'));

        $result = $this->controller->destroy(999);
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
    }

    public function testDestroyInUse(): void {
        $this->service->method('deleteGlobal')
            ->willThrowException(new NoteTypeInUseException('Note type is still used by 3 note(s)'));

        $result = $this->controller->destroy(1);
        $this->assertSame(Http::STATUS_CONFLICT, $result->getStatus());
    }

    public function testNotFoundResponseContainsGenericMessage(): void {
        // The raw exception message is not leaked; a generic message is returned.
        $this->service->method('updateGlobal')
            ->willThrowException(new NoteTypeNotFoundException('Type 999 not found'));

        $result = $this->controller->update(999, 'Name');
        $this->assertSame(Http::STATUS_NOT_FOUND, $result->getStatus());
        $this->assertSame('Not found', $result->getData()['message']);
    }

    /**
     * AdminNoteTypeController's entire access-control model rests on the
     * ABSENCE of #[NoAdminRequired] on the class and every action method —
     * Nextcloud's core Controller dispatch then enforces admin-group
     * membership before the action ever runs (see routes.php's comment "no
     * #[NoAdminRequired] -> admin-only"). This is invisible to every other
     * test in this file, which calls methods directly and bypasses dispatch
     * entirely. A reflection-based assertion is the only thing in the
     * PHPUnit layer that can catch a future refactor silently reintroducing
     * the attribute (e.g. someone "fixing" a perceived bug by copy-pasting it
     * from NoteTypeController, or a bad merge). e2e coverage
     * (crm-admin-note-types.spec.js's "admin-only" describe block) is the
     * complementary end-to-end proof that the framework-level gate actually
     * rejects a non-admin caller at the HTTP layer; this test only proves the
     * attribute itself is absent from the source.
     *
     * @dataProvider provideActionMethodNames
     */
    public function testActionMethodsDoNotCarryNoAdminRequired(string $methodName): void {
        $reflection = new ReflectionClass(AdminNoteTypeController::class);
        $method = $reflection->getMethod($methodName);
        $attributes = $method->getAttributes(NoAdminRequired::class);
        $this->assertCount(
            0,
            $attributes,
            "AdminNoteTypeController::{$methodName}() must NOT carry #[NoAdminRequired] "
            . '— its admin-only access control relies entirely on this attribute being absent.'
        );
    }

    public function testControllerClassDoesNotCarryNoAdminRequired(): void {
        $reflection = new ReflectionClass(AdminNoteTypeController::class);
        $attributes = $reflection->getAttributes(NoAdminRequired::class);
        $this->assertCount(
            0,
            $attributes,
            'AdminNoteTypeController must NOT carry a class-level #[NoAdminRequired].'
        );
    }

    public static function provideActionMethodNames(): array {
        return [
            'index' => ['index'],
            'usage' => ['usage'],
            'create' => ['create'],
            'update' => ['update'],
            'destroy' => ['destroy'],
        ];
    }

    /**
     * Verify that #[UserRateLimit] is present on create()/update()/destroy(),
     * matching NoteTypeController and NoteController. This prevents the
     * rate limit from being silently dropped during refactoring.
     */
    public function testCreateUpdateDestroyHaveUserRateLimitAttribute(): void {
        foreach (['create', 'update', 'destroy'] as $method) {
            $ref = new \ReflectionMethod(AdminNoteTypeController::class, $method);
            $attrs = $ref->getAttributes(\OCP\AppFramework\Http\Attribute\UserRateLimit::class);
            $this->assertNotEmpty(
                $attrs,
                "AdminNoteTypeController::{$method}() must carry the #[UserRateLimit] attribute",
            );
        }
    }
}
