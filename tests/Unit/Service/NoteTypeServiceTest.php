<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Service;

use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Db\NoteTypeMapper;
use OCA\Touchpoint\Service\NoteTypeInUseException;
use OCA\Touchpoint\Service\NoteTypeNotFoundException;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\NoteValidationException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\DB\Exception as DBException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteTypeServiceTest extends TestCase {

    private NoteTypeService $service;
    private NoteTypeMapper $mapper;
    private NoteMapper $noteMapper;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->mapper = $this->createMock(NoteTypeMapper::class);
        $this->noteMapper = $this->createMock(NoteMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        // Default: no notes reference any type, so delete is unblocked.
        $this->noteMapper->method('countByNoteType')->willReturn(0);
        $this->service = new NoteTypeService($this->mapper, $this->noteMapper, $this->logger);
    }

    public function testFindAll(): void {
        $types = [new NoteType(), new NoteType()];
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with('user1')
            ->willReturn($types);

        $result = $this->service->findAll('user1');
        $this->assertCount(2, $result);
    }

    public function testFindAllEmpty(): void {
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with('user1')
            ->willReturn([]);

        $result = $this->service->findAll('user1');
        $this->assertEmpty($result);
    }

    public function testFindSuccess(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Call');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with(1, 'user1')
            ->willReturn($noteType);

        $result = $this->service->find(1, 'user1');
        $this->assertSame('Call', $result->getName());
    }

    public function testFindNotExisting(): void {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->with(999, 'user1')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->find(999, 'user1');
    }

    public function testFindMultipleObjectsReturned(): void {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->with(1, 'user1')
            ->willThrowException(new MultipleObjectsReturnedException('Multiple'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->find(1, 'user1');
    }

    public function testCreate(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getName() === 'Call'
                    && $nt->getIcon() === 'icon-phone'
                    && $nt->getColor() === '#2ecc71'
                    && $nt->getUserId() === 'user1'
                    && $nt->getIsDefault() === false;
            }))
            ->willReturnCallback(function (NoteType $nt) {
                $nt->setId(1);
                return $nt;
            });

        $result = $this->service->create('Call', 'icon-phone', '#2ecc71', 'user1');
        $this->assertSame('Call', $result->getName());
        $this->assertSame('icon-phone', $result->getIcon());
        $this->assertSame('#2ecc71', $result->getColor());
    }

    public function testCreateWithDefault(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getIsDefault() === true;
            }))
            ->willReturnArgument(0);

        $result = $this->service->create('General', 'icon-note', '#0082c9', 'user1', true);
        $this->assertTrue($result->getIsDefault());
    }

    public function testUpdate(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setIcon('icon-phone');
        $noteType->setColor('#000000');
        $noteType->setUserId('user1');

        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->with(1, 'user1')
            ->willReturn($noteType);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getName() === 'New'
                    && $nt->getIcon() === 'icon-mail'
                    && $nt->getColor() === '#ffffff';
            }))
            ->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', 'New', 'icon-mail', '#ffffff');
        $this->assertSame('New', $result->getName());
    }

    public function testUpdatePartialPreservesUntouchedFields(): void {
        // A name-only update must not reset the icon/color (PATCH-like).
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setIcon('icon-phone');
        $noteType->setColor('#123456');
        $noteType->setUserId('user1');

        $this->mapper->method('findOwnedById')->with(1, 'user1')->willReturn($noteType);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getName() === 'Renamed'
                    && $nt->getIcon() === 'icon-phone'
                    && $nt->getColor() === '#123456';
            }))
            ->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', 'Renamed');
        $this->assertSame('Renamed', $result->getName());
    }

    public function testUpdateNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->update(999, 'user1', 'Name', 'icon-note', '#000');
    }

    public function testUpdateRejectsTypeNotOwned(): void {
        // findOwnedById only returns the caller's own types; a type owned by
        // someone else is reported as not found.
        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->with(5, 'attacker')
            ->willThrowException(new DoesNotExistException('not owned'));

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->update(5, 'attacker', 'Hijack', 'icon-note', '#000');
    }

    public function testDelete(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Call');
        $noteType->setUserId('user1');

        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->with(1, 'user1')
            ->willReturn($noteType);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($noteType);

        $result = $this->service->delete(1, 'user1');
        $this->assertSame('Call', $result->getName());
    }

    public function testDeleteNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->delete(999, 'user1');
    }

    public function testDeleteRejectsTypeNotOwned(): void {
        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->with(5, 'attacker')
            ->willThrowException(new DoesNotExistException('not owned'));

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->delete(5, 'attacker');
    }

    public function testDeleteBlockedWhenTypeInUse(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setUserId('user1');

        $mapper = $this->createMock(NoteTypeMapper::class);
        $mapper->method('findOwnedById')->with(1, 'user1')->willReturn($noteType);
        $mapper->expects($this->never())->method('delete');

        $noteMapper = $this->createMock(NoteMapper::class);
        // Three notes still reference this type — delete must be blocked.
        $noteMapper->method('countByNoteType')->with(1, 'user1')->willReturn(3);

        $service = new NoteTypeService($mapper, $noteMapper, $this->logger);

        $this->expectException(NoteTypeInUseException::class);
        $service->delete(1, 'user1');
    }

    public function testCountUsageDelegatesToMapper(): void {
        $noteMapper = $this->createMock(NoteMapper::class);
        $noteMapper->method('countByNoteType')->with(7, 'user1')->willReturn(4);
        $service = new NoteTypeService($this->mapper, $noteMapper, $this->logger);

        $this->assertSame(4, $service->countUsage(7, 'user1'));
    }

    public function testCountGlobalUsageDelegatesToMapperWithoutUserId(): void {
        // Unlike countUsage(), this must call countByNoteType() with no
        // $userId arg so the count is system-wide, matching deleteGlobal()'s
        // own guard.
        $noteType = new NoteType();
        $noteType->setId(7);
        $noteType->setUserId('');
        $noteType->setIsDefault(true);
        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->with(7)
            ->willReturn($noteType);

        $noteMapper = $this->createMock(NoteMapper::class);
        $noteMapper->expects($this->once())->method('countByNoteType')->with(7)->willReturn(9);
        $service = new NoteTypeService($this->mapper, $noteMapper, $this->logger);

        $this->assertSame(9, $service->countGlobalUsage(7));
    }

    public function testCountGlobalUsageNotFoundForNonGlobalId(): void {
        // Mirrors testUpdateGlobalNotFound()/testDeleteGlobalNotFound(): the
        // admin usage-check endpoint must share the same authorization
        // boundary as its update/delete siblings, not return a count for an
        // id that doesn't name a real global default (e.g. a regular user's
        // private note type).
        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $noteMapper = $this->createMock(NoteMapper::class);
        $noteMapper->expects($this->never())->method('countByNoteType');
        $service = new NoteTypeService($this->mapper, $noteMapper, $this->logger);

        $this->expectException(NoteTypeNotFoundException::class);
        $service->countGlobalUsage(999);
    }

    public function testCreateNormalisesInvalidColor(): void {
        // An arbitrary/unsafe color string must be replaced with a safe default
        // so it never lands in an inline style.
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (NoteType $nt) => $nt->getColor() === '#0082c9'))
            ->willReturnArgument(0);

        $result = $this->service->create('X', 'icon-note', 'red; background:url(x)', 'user1');
        $this->assertSame('#0082c9', $result->getColor());
    }

    public function testCreateNormalisesOverLengthColor(): void {
        // An over-length color string must be rejected up front (before the
        // regex checks even run) and replaced with the safe default, mirroring
        // assertNameLength()/assertValidIcon()'s explicit length guards.
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (NoteType $nt) => $nt->getColor() === '#0082c9'))
            ->willReturnArgument(0);

        $result = $this->service->create('X', 'icon-note', str_repeat('#', 33), 'user1');
        $this->assertSame('#0082c9', $result->getColor());
    }

    public function testCreateAcceptsValidHexAndHsl(): void {
        $colors = [];
        $this->mapper->method('insert')
            ->willReturnCallback(function (NoteType $nt) use (&$colors) {
                $colors[] = $nt->getColor();
                return $nt;
            });

        $this->service->create('A', 'icon-note', '#abc', 'user1');
        $this->service->create('B', 'icon-note', '#A1B2C3', 'user1');
        $this->service->create('C', 'icon-note', 'hsl(210, 50%, 40%)', 'user1');

        $this->assertSame(['#abc', '#A1B2C3', 'hsl(210, 50%, 40%)'], $colors);
    }

    public function testSeedDefaultsCreatesGlobalSetWhenAbsent(): void {
        // findGlobalDefaults() is now called twice on the insert path: once for
        // the initial "already seeded?" check (empty), once at the end to build
        // the returned global-defaults set (see NoteTypeService::seedDefaults()'s
        // docblock — the return value lets callers like Admin::getForm() avoid a
        // second, separate query of their own).
        $this->mapper->expects($this->exactly(2))
            ->method('findGlobalDefaults')
            ->willReturn([]);

        $this->mapper->expects($this->exactly(5))
            ->method('insert')
            ->willReturnCallback(function (NoteType $nt) {
                static $id = 0;
                $nt->setId(++$id);
                return $nt;
            });

        $result = $this->service->seedDefaults('user1');
        $this->assertSame([], $result);
    }

    public function testSeedDefaultsSkipsIfGlobalSetExists(): void {
        $existing = [new NoteType()];
        $this->mapper->expects($this->once())
            ->method('findGlobalDefaults')
            ->willReturn($existing);

        $this->mapper->expects($this->never())->method('insert');

        $result = $this->service->seedDefaults('user1');
        $this->assertSame($existing, $result);
    }

    public function testSeedDefaultsCorrectNames(): void {
        $this->mapper->method('findGlobalDefaults')->willReturn([]);

        $insertedNames = [];
        $this->mapper->expects($this->exactly(5))
            ->method('insert')
            ->willReturnCallback(function (NoteType $nt) use (&$insertedNames) {
                $insertedNames[] = $nt->getName();
                return $nt;
            });

        $this->service->seedDefaults('admin');

        $this->assertContains('Call', $insertedNames);
        $this->assertContains('Meeting', $insertedNames);
        $this->assertContains('Email', $insertedNames);
        $this->assertContains('Task', $insertedNames);
        $this->assertContains('General', $insertedNames);
    }

    public function testSeedDefaultsUseRenderableIconTokens(): void {
        // Every seeded icon token must be one the render surfaces actually
        // resolve (src/utils/noteTypeIcon.js). 'icon-calendar'/'icon-note' are
        // NOT rendered there, so Meeting/General must use 'icon-calendar-dark'
        // and 'icon-category-office' instead — otherwise those default badges
        // show no icon.
        $this->mapper->method('findGlobalDefaults')->willReturn([]);

        $byName = [];
        $this->mapper->expects($this->exactly(5))
            ->method('insert')
            ->willReturnCallback(function (NoteType $nt) use (&$byName) {
                $byName[$nt->getName()] = $nt->getIcon();
                return $nt;
            });

        $this->service->seedDefaults('admin');

        // Tokens rendered by src/utils/noteTypeIcon.js ICON_COMPONENTS/ICON_PATHS.
        $renderable = [
            'icon-comment',
            'icon-phone',
            'icon-calendar-dark',
            'icon-mail',
            'icon-checkmark',
            'icon-star',
            'icon-link',
            'icon-category-office',
            // legacy aliases that are now also mapped in the JS maps
            'icon-calendar',
            'icon-note',
        ];
        foreach ($byName as $name => $icon) {
            $this->assertContains($icon, $renderable, "Seeded $name uses a non-renderable icon token: $icon");
        }
        // The two formerly-broken defaults must use the canonical rendered tokens.
        $this->assertSame('icon-calendar-dark', $byName['Meeting']);
        $this->assertSame('icon-category-office', $byName['General']);
    }

    public function testCreateRejectsDuplicateName(): void {
        // The UNIQUE(user_id, name) index (Version1009) surfaces a duplicate
        // name as a DBException; create() must translate it into a clean
        // validation error (400) rather than letting it escape as a 500.
        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->mapper->expects($this->once())->method('insert')->willThrowException($e);

        try {
            $this->service->create('Call', 'icon-phone', '#2ecc71', 'user1');
            $this->fail('Expected NoteValidationException was not thrown');
        } catch (NoteValidationException $validationException) {
            // getErrorCode() is the stable, machine-readable identifier
            // ErrorHandler surfaces as `code` — clients must branch on this,
            // not on the (translated) message text; see docs/API.md.
            $this->assertSame('duplicate_name', $validationException->getErrorCode());
        }
    }

    public function testCreateRethrowsNonUniqueDbException(): void {
        // A non-duplicate DB failure must propagate unchanged.
        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_CONNECTION_LOST);
        $this->mapper->method('insert')->willThrowException($e);

        $this->expectException(DBException::class);
        $this->service->create('Call', 'icon-phone', '#2ecc71', 'user1');
    }

    public function testUpdateRejectsDuplicateName(): void {
        // Renaming a type to a name the user already owns hits the unique index;
        // update() must translate it into a validation error, not a 500.
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setIcon('icon-phone');
        $noteType->setColor('#123456');
        $noteType->setUserId('user1');
        $this->mapper->method('findOwnedById')->with(1, 'user1')->willReturn($noteType);

        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->mapper->expects($this->once())->method('update')->willThrowException($e);

        try {
            $this->service->update(1, 'user1', 'Existing');
            $this->fail('Expected NoteValidationException was not thrown');
        } catch (NoteValidationException $validationException) {
            $this->assertSame('duplicate_name', $validationException->getErrorCode());
        }
    }

    public function testUpdateRethrowsNonUniqueDbException(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setIcon('icon-phone');
        $noteType->setColor('#123456');
        $noteType->setUserId('user1');
        $this->mapper->method('findOwnedById')->with(1, 'user1')->willReturn($noteType);

        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_CONNECTION_LOST);
        $this->mapper->method('update')->willThrowException($e);

        $this->expectException(DBException::class);
        $this->service->update(1, 'user1', 'Renamed');
    }

    public function testCreateRejectsBlankName(): void {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->create('', 'icon-phone', '#2ecc71', 'user1');
    }

    public function testCreateRejectsWhitespaceOnlyName(): void {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->create('   ', 'icon-phone', '#2ecc71', 'user1');
    }

    public function testUpdateRejectsBlankName(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setUserId('user1');
        $this->mapper->method('findOwnedById')->with(1, 'user1')->willReturn($noteType);
        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteValidationException::class);
        $this->service->update(1, 'user1', '   ');
    }

    public function testCreateRejectsUnknownIcon(): void {
        // An icon token the render surfaces can't resolve must fail fast with a
        // validation error rather than being stored and silently dropped.
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->create('X', 'icon-bogus', '#0082c9', 'user1');
    }

    public function testCreateRejectsOverlongIcon(): void {
        // Longer than the VARCHAR(64) column bound — must not reach the DB.
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->create('X', str_repeat('a', 65), '#0082c9', 'user1');
    }

    public function testUpdateRejectsUnknownIcon(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setUserId('user1');
        $this->mapper->method('findOwnedById')->with(1, 'user1')->willReturn($noteType);
        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteValidationException::class);
        $this->service->update(1, 'user1', 'Name', 'icon-bogus', '#0082c9');
    }

    public function testCreateAcceptsKnownIcons(): void {
        $icons = [];
        $this->mapper->method('insert')
            ->willReturnCallback(function (NoteType $nt) use (&$icons) {
                $icons[] = $nt->getIcon();
                return $nt;
            });

        foreach (['icon-phone', 'icon-calendar-dark', 'icon-mail', 'icon-note', 'icon-star'] as $icon) {
            $this->service->create('X', $icon, '#0082c9', 'user1');
        }

        $this->assertSame(
            ['icon-phone', 'icon-calendar-dark', 'icon-mail', 'icon-note', 'icon-star'],
            $icons,
        );
    }

    public function testSeedDefaultsSeedsSharedGlobalSet(): void {
        // Defaults are a single shared global set: empty user_id + is_default =
        // true. Read-shared to everyone, owned by no one, so no user can mutate
        // them and no user's types ever leak to another.
        $this->mapper->method('findGlobalDefaults')->willReturn([]);

        $this->mapper->expects($this->exactly(5))
            ->method('insert')
            ->willReturnCallback(function (NoteType $nt) {
                $this->assertSame('', $nt->getUserId());
                $this->assertTrue($nt->getIsDefault());
                return $nt;
            });

        $this->service->seedDefaults('admin');
    }

    public function testSeedDefaultsToleratesConcurrentDuplicateInsert(): void {
        // A concurrent first request can pass the empty-globals check too; its
        // duplicate INSERT then hits the UNIQUE(user_id, name) index. seedDefaults
        // must catch REASON_UNIQUE_CONSTRAINT_VIOLATION and keep going so the
        // instance never ends up with a doubled global set, rather than 500ing.
        $this->mapper->method('findGlobalDefaults')->willReturn([]);

        $inserted = 0;
        $this->mapper->expects($this->exactly(5))
            ->method('insert')
            ->willReturnCallback(function (NoteType $nt) use (&$inserted) {
                $inserted++;
                // Simulate the race losing on the 2nd and 4th inserts.
                if (in_array($nt->getName(), ['Meeting', 'Task'], true)) {
                    $e = $this->createMock(DBException::class);
                    $e->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
                    throw $e;
                }
                return $nt;
            });

        // Must not throw.
        $this->service->seedDefaults('admin');
        $this->assertSame(5, $inserted);
    }

    public function testSeedDefaultsRethrowsNonUniqueDbException(): void {
        // A genuine DB failure (not a duplicate) must propagate, not be swallowed.
        $this->mapper->method('findGlobalDefaults')->willReturn([]);

        // Any reason other than REASON_UNIQUE_CONSTRAINT_VIOLATION must propagate.
        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_CONNECTION_LOST);
        $this->mapper->method('insert')->willThrowException($e);

        $this->expectException(DBException::class);
        $this->service->seedDefaults('admin');
    }

    // --- Admin global note type management ---------------------------------

    public function testFindGlobalDefaultsDelegatesToMapper(): void {
        $types = [new NoteType(), new NoteType()];
        $this->mapper->expects($this->once())
            ->method('findGlobalDefaults')
            ->willReturn($types);

        $result = $this->service->findGlobalDefaults();
        $this->assertCount(2, $result);
    }

    public function testFindGlobalDefaultsReturnsEmpty(): void {
        $this->mapper->expects($this->once())
            ->method('findGlobalDefaults')
            ->willReturn([]);

        $this->assertSame([], $this->service->findGlobalDefaults());
    }

    public function testCreateGlobal(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getName() === 'Custom'
                    && $nt->getIcon() === 'icon-star'
                    && $nt->getColor() === '#ff0000'
                    && $nt->getUserId() === ''
                    && $nt->getIsDefault() === true;
            }))
            ->willReturnCallback(function (NoteType $nt) {
                $nt->setId(1);
                return $nt;
            });

        $result = $this->service->createGlobal('Custom', 'icon-star', '#ff0000');
        $this->assertSame('Custom', $result->getName());
        $this->assertTrue($result->getIsDefault());
        $this->assertSame('', $result->getUserId());
    }

    public function testCreateGlobalRejectsOverlongName(): void {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->createGlobal(str_repeat('a', 200), 'icon-star', '#ff0000');
    }

    public function testCreateGlobalRejectsBlankName(): void {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->createGlobal('   ', 'icon-star', '#ff0000');
    }

    public function testCreateGlobalRejectsUnknownIcon(): void {
        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->createGlobal('Custom', 'icon-bogus', '#ff0000');
    }

    public function testCreateGlobalNormalisesInvalidColor(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (NoteType $nt) => $nt->getColor() === '#0082c9'))
            ->willReturnArgument(0);

        $result = $this->service->createGlobal('Custom', 'icon-star', 'red; background:url(x)');
        $this->assertSame('#0082c9', $result->getColor());
    }

    public function testCreateGlobalRejectsDuplicateName(): void {
        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->mapper->expects($this->once())->method('insert')->willThrowException($e);

        try {
            $this->service->createGlobal('Call', 'icon-phone', '#2ecc71');
            $this->fail('Expected NoteValidationException was not thrown');
        } catch (NoteValidationException $validationException) {
            $this->assertSame('duplicate_name', $validationException->getErrorCode());
        }
    }

    public function testCreateGlobalRethrowsNonUniqueDbException(): void {
        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_CONNECTION_LOST);
        $this->mapper->method('insert')->willThrowException($e);

        $this->expectException(DBException::class);
        $this->service->createGlobal('Call', 'icon-phone', '#2ecc71');
    }

    public function testUpdateGlobal(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setIcon('icon-phone');
        $noteType->setColor('#000000');
        $noteType->setUserId('');
        $noteType->setIsDefault(true);

        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->with(1)
            ->willReturn($noteType);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getName() === 'New'
                    && $nt->getIcon() === 'icon-mail'
                    && $nt->getColor() === '#ffffff';
            }))
            ->willReturnArgument(0);

        $result = $this->service->updateGlobal(1, 'New', 'icon-mail', '#ffffff');
        $this->assertSame('New', $result->getName());
    }

    public function testUpdateGlobalPartialPreservesUntouchedFields(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setIcon('icon-phone');
        $noteType->setColor('#123456');
        $noteType->setUserId('');
        $noteType->setIsDefault(true);

        $this->mapper->method('findGlobalById')->with(1)->willReturn($noteType);

        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(function (NoteType $nt) {
                return $nt->getName() === 'Renamed'
                    && $nt->getIcon() === 'icon-phone'
                    && $nt->getColor() === '#123456';
            }))
            ->willReturnArgument(0);

        $result = $this->service->updateGlobal(1, 'Renamed');
        $this->assertSame('Renamed', $result->getName());
    }

    public function testUpdateGlobalRejectsBlankName(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setUserId('');
        $noteType->setIsDefault(true);
        $this->mapper->method('findGlobalById')->with(1)->willReturn($noteType);
        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteValidationException::class);
        $this->service->updateGlobal(1, '   ');
    }

    public function testUpdateGlobalNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->updateGlobal(999, 'Name', 'icon-note', '#000');
    }

    public function testUpdateGlobalMultipleObjectsReturned(): void {
        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->willThrowException(new MultipleObjectsReturnedException('Multiple'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->updateGlobal(1, 'Name');
    }

    public function testUpdateGlobalRejectsUnknownIcon(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setUserId('');
        $noteType->setIsDefault(true);
        $this->mapper->method('findGlobalById')->with(1)->willReturn($noteType);
        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteValidationException::class);
        $this->service->updateGlobal(1, 'Name', 'icon-bogus', '#0082c9');
    }

    public function testUpdateGlobalRejectsDuplicateName(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setUserId('');
        $noteType->setIsDefault(true);
        $this->mapper->method('findGlobalById')->with(1)->willReturn($noteType);

        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->mapper->expects($this->once())->method('update')->willThrowException($e);

        try {
            $this->service->updateGlobal(1, 'Existing');
            $this->fail('Expected NoteValidationException was not thrown');
        } catch (NoteValidationException $validationException) {
            $this->assertSame('duplicate_name', $validationException->getErrorCode());
        }
    }

    public function testUpdateGlobalRethrowsNonUniqueDbException(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Old');
        $noteType->setUserId('');
        $noteType->setIsDefault(true);
        $this->mapper->method('findGlobalById')->with(1)->willReturn($noteType);

        $e = $this->createMock(DBException::class);
        $e->method('getReason')->willReturn(DBException::REASON_CONNECTION_LOST);
        $this->mapper->method('update')->willThrowException($e);

        $this->expectException(DBException::class);
        $this->service->updateGlobal(1, 'Renamed');
    }

    public function testDeleteGlobal(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setName('Custom');
        $noteType->setUserId('');
        $noteType->setIsDefault(true);

        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->with(1)
            ->willReturn($noteType);

        // deleteGlobal() now reuses NoteMapper::countByNoteType() (no $userId
        // arg -> system-wide count) instead of a dedicated mapper method.
        $this->noteMapper->expects($this->once())
            ->method('countByNoteType')
            ->with(1)
            ->willReturn(0);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($noteType);

        $result = $this->service->deleteGlobal(1);
        $this->assertSame('Custom', $result->getName());
    }

    public function testDeleteGlobalNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->deleteGlobal(999);
    }

    public function testDeleteGlobalMultipleObjectsReturned(): void {
        $this->mapper->expects($this->once())
            ->method('findGlobalById')
            ->willThrowException(new MultipleObjectsReturnedException('Multiple'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->deleteGlobal(1);
    }

    public function testDeleteGlobalBlockedWhenInUse(): void {
        $noteType = new NoteType();
        $noteType->setId(1);
        $noteType->setUserId('');
        $noteType->setIsDefault(true);

        $mapper = $this->createMock(NoteTypeMapper::class);
        $mapper->method('findGlobalById')->with(1)->willReturn($noteType);
        $mapper->expects($this->never())->method('delete');

        $noteMapper = $this->createMock(NoteMapper::class);
        // Three notes still reference this type (system-wide, no $userId arg)
        // — delete must be blocked. A fresh mock is required here rather than
        // re-stubbing $this->noteMapper: setUp()'s unconstrained
        // countByNoteType() => 0 stub would otherwise win over this one (first
        // unconstrained stub wins).
        $noteMapper->method('countByNoteType')->with(1)->willReturn(3);

        $service = new NoteTypeService($mapper, $noteMapper, $this->logger);

        $this->expectException(NoteTypeInUseException::class);
        $service->deleteGlobal(1);
    }
}
