<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Service;

use OCA\CrmNotes\Db\NoteMapper;
use OCA\CrmNotes\Db\NoteType;
use OCA\CrmNotes\Db\NoteTypeMapper;
use OCA\CrmNotes\Service\NoteTypeInUseException;
use OCA\CrmNotes\Service\NoteTypeNotFoundException;
use OCA\CrmNotes\Service\NoteTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
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
        $noteType->setIcon('icon-old');
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
                    && $nt->getIcon() === 'icon-new'
                    && $nt->getColor() === '#ffffff';
            }))
            ->willReturnArgument(0);

        $result = $this->service->update(1, 'New', 'icon-new', '#ffffff', 'user1');
        $this->assertSame('New', $result->getName());
    }

    public function testUpdateNotFound(): void {
        $this->mapper->expects($this->once())
            ->method('findOwnedById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteTypeNotFoundException::class);
        $this->service->update(999, 'Name', 'icon', '#000', 'user1');
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
        $this->service->update(5, 'Hijack', 'icon', '#000', 'attacker');
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

    public function testCreateAcceptsValidHexAndHsl(): void {
        $colors = [];
        $this->mapper->method('insert')
            ->willReturnCallback(function (NoteType $nt) use (&$colors) {
                $colors[] = $nt->getColor();
                return $nt;
            });

        $this->service->create('A', 'i', '#abc', 'user1');
        $this->service->create('B', 'i', '#A1B2C3', 'user1');
        $this->service->create('C', 'i', 'hsl(210, 50%, 40%)', 'user1');

        $this->assertSame(['#abc', '#A1B2C3', 'hsl(210, 50%, 40%)'], $colors);
    }

    public function testSeedDefaultsCreatesGlobalSetWhenAbsent(): void {
        $this->mapper->expects($this->once())
            ->method('findGlobalDefaults')
            ->willReturn([]);

        $this->mapper->expects($this->exactly(5))
            ->method('insert')
            ->willReturnCallback(function (NoteType $nt) {
                static $id = 0;
                $nt->setId(++$id);
                return $nt;
            });

        $this->service->seedDefaults('user1');
    }

    public function testSeedDefaultsSkipsIfGlobalSetExists(): void {
        $existing = [new NoteType()];
        $this->mapper->expects($this->once())
            ->method('findGlobalDefaults')
            ->willReturn($existing);

        $this->mapper->expects($this->never())->method('insert');

        $this->service->seedDefaults('user1');
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
}
