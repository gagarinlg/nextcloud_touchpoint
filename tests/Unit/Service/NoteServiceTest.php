<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Service;

use DateTime;
use OCA\CrmNotes\Db\Note;
use OCA\CrmNotes\Db\NoteContact;
use OCA\CrmNotes\Db\NoteContactMapper;
use OCA\CrmNotes\Db\NoteFile;
use OCA\CrmNotes\Db\NoteFileMapper;
use OCA\CrmNotes\Db\NoteMapper;
use OCA\CrmNotes\Db\NoteSharingMapper;
use OCA\CrmNotes\Db\NoteType;
use OCA\CrmNotes\Service\NoteForbiddenException;
use OCA\CrmNotes\Service\NoteNotFoundException;
use OCA\CrmNotes\Service\NoteService;
use OCA\CrmNotes\Service\NoteTypeNotFoundException;
use OCA\CrmNotes\Service\NoteTypeService;
use OCA\CrmNotes\Service\NoteValidationException;
use OCA\CrmNotes\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class NoteServiceTest extends TestCase {

    private NoteService $service;
    private NoteMapper $mapper;
    private NoteContactMapper $noteContactMapper;
    private NoteFileMapper $noteFileMapper;
    private NoteSharingMapper $noteSharingMapper;
    private SettingsService $settingsService;
    private NoteTypeService $noteTypeService;
    private IRootFolder $rootFolder;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->mapper = $this->createMock(NoteMapper::class);
        $this->noteContactMapper = $this->createMock(NoteContactMapper::class);
        $this->noteFileMapper = $this->createMock(NoteFileMapper::class);
        $this->noteSharingMapper = $this->createMock(NoteSharingMapper::class);
        $this->settingsService = $this->createMock(SettingsService::class);
        $this->noteTypeService = $this->createMock(NoteTypeService::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        // Default: any referenced note type is visible to the caller.
        $this->noteTypeService->method('find')->willReturn(new NoteType());

        // Default: private mode, no group membership, no shares.
        $this->settingsService->method('isNotesPublic')->willReturn(false);
        $this->settingsService->method('getUserGroupIds')->willReturn([]);
        $this->settingsService->method('getUserShareTargets')->willReturn([]);
        // By default every well-formed share principal exists.
        $this->settingsService->method('principalExists')->willReturn(true);
        $this->noteSharingMapper->method('findAccessibleNoteIds')->willReturn([]);
        $this->noteSharingMapper->method('findWritableNoteIds')->willReturn([]);
        $this->noteSharingMapper->method('findByNoteId')->willReturn([]);
        $this->noteSharingMapper->method('findByNoteIds')->willReturn([]);

        // enrichNote/enrichNotes default to empty extra data.
        $this->noteContactMapper->method('findByNoteId')->willReturn([]);
        $this->noteContactMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper->method('findByNoteId')->willReturn([]);
        $this->noteFileMapper->method('findByNoteIds')->willReturn([]);

        $this->service = $this->makeService();
    }

    private function makeService(): NoteService {
        return new NoteService(
            $this->mapper,
            $this->noteContactMapper,
            $this->noteFileMapper,
            $this->noteSharingMapper,
            $this->settingsService,
            $this->noteTypeService,
            $this->rootFolder,
            $this->logger,
        );
    }

    /**
     * Build a user folder that resolves the given file id/path so addFile's
     * IDOR validation passes.
     */
    private function mockUserFolderWithFile(int $fileId, string $path): void {
        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn($fileId);
        $node->method('getPath')->willReturn('/user1/files' . $path);

        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->with($fileId)->willReturn([$node]);
        $folder->method('get')->with($path)->willReturn($node);
        $folder->method('getRelativePath')->willReturn($path);

        $this->rootFolder->method('getUserFolder')->with('user1')->willReturn($folder);
    }

    private function mockEmptyUserFolder(): void {
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->willReturn([]);
        $folder->method('getRelativePath')->willReturn(null);
        $this->rootFolder->method('getUserFolder')->willReturn($folder);
    }

    public function testFindAll(): void {
        // Private mode pushes ordering and paging all the way down to SQL via
        // findAccessiblePage(); the service must not load or PHP-sort the full
        // key set. The DB returns the already-ordered page directly.
        $n1 = new Note(); $n1->setId(1); $n1->setUserId('user1');
        $n2 = new Note(); $n2->setId(2); $n2->setUserId('user1');
        $n3 = new Note(); $n3->setId(3); $n3->setUserId('user1');
        $this->mapper->expects($this->once())
            ->method('findAccessiblePage')
            ->with('user1', $this->anything(), null, null)
            ->willReturn([$n1, $n2, $n3]);

        $result = $this->service->findAll('user1');
        $this->assertCount(3, $result);
        // Order is whatever the (SQL-ordered) mapper returns, preserved as-is.
        $this->assertSame(1, $result[0]->getId());
        $this->assertSame(2, $result[1]->getId());
        $this->assertSame(3, $result[2]->getId());
    }

    public function testFindAllPaginatesMergedOwnedAndShared(): void {
        // Owned notes 1 and 3; shared note 2. The service must hand the shared
        // id set and the requested window straight to findAccessiblePage(),
        // which performs the merged ORDER BY + LIMIT/OFFSET in SQL.
        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([2]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $n1 = new Note(); $n1->setId(1); $n1->setUserId('user1');
        $n2 = new Note(); $n2->setId(2); $n2->setUserId('owner');
        $this->mapper->expects($this->once())
            ->method('findAccessiblePage')
            ->with('user1', [2], 2, 0)
            ->willReturn([$n1, $n2]);

        $result = $this->makeService()->findAll('user1', 2, 0);
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]->getId());
        $this->assertSame(2, $result[1]->getId());
    }

    public function testFindSuccess(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('Test note');
        $note->setUserId('user1');

        $this->mapper->expects($this->once())
            ->method('findById')
            ->with(1, 'user1')
            ->willReturn($note);

        $result = $this->service->find(1, 'user1');
        $this->assertSame('Test note', $result->getTitle());
    }

    public function testFindNotExisting(): void {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteNotFoundException::class);
        $this->service->find(999, 'user1');
    }

    public function testFindMultipleObjectsReturned(): void {
        $this->mapper->expects($this->once())
            ->method('findById')
            ->willThrowException(new MultipleObjectsReturnedException('Multiple'));

        $this->expectException(NoteNotFoundException::class);
        $this->service->find(1, 'user1');
    }

    public function testFindByContact(): void {
        $note1 = new Note();
        $note1->setId(1);
        $note1->setUserId('user1');
        $note2 = new Note();
        $note2->setId(2);
        $note2->setUserId('user1');

        $nc = new NoteContact();
        $nc->setNoteId(1);
        $nc->setContactUid('contact-123');

        $this->noteContactMapper->method('findByContactUid')
            ->with('contact-123')
            ->willReturn([$nc]);

        // The legacy contact_uid lookup is intentionally un-owner-scoped (null)
        // so notes shared with the caller but linked only via the legacy column
        // are still collected as candidates.
        $this->mapper->method('findByContact')
            ->with('contact-123', null)
            ->willReturn([$note2]);

        // Visibility is decided on the id set BEFORE loading full rows: the
        // owner-scoped sort-key lookup confirms the caller owns both candidates.
        $this->mapper->method('findSortKeysByIds')->willReturnCallback(
            function (array $ids, ?string $userId) {
                $keys = [];
                foreach ($ids as $id) {
                    $keys[$id] = [
                        'updated_at' => sprintf('2026-06-0%d 10:00:00', $id),
                        'created_at' => null,
                        'is_pinned' => false,
                    ];
                }
                return $keys;
            }
        );

        // Only the windowed page of full rows is loaded, unscoped.
        $this->mapper->method('findByIds')->willReturn([$note1, $note2]);

        $result = $this->service->findByContact('contact-123', 'user1');
        $this->assertCount(2, $result);
        // Ordered by updated_at desc: note 2 (06-02) before note 1 (06-01).
        $this->assertSame(2, $result[0]->getId());
        $this->assertSame(1, $result[1]->getId());
    }

    public function testFindByContactSortsPinnedFirst(): void {
        // A pinned older note must sort ahead of an unpinned newer note. The
        // pinned-first ordering is applied on the id window (sort keys), not on
        // the full enriched rows.
        $pinnedOld = new Note();
        $pinnedOld->setId(1);
        $pinnedOld->setUserId('user1');
        $freshUnpinned = new Note();
        $freshUnpinned->setId(2);
        $freshUnpinned->setUserId('user1');

        $nc1 = new NoteContact(); $nc1->setNoteId(1); $nc1->setContactUid('c');
        $nc2 = new NoteContact(); $nc2->setNoteId(2); $nc2->setContactUid('c');
        $this->noteContactMapper->method('findByContactUid')->willReturn([$nc1, $nc2]);
        $this->mapper->method('findByContact')->willReturn([]);

        $this->mapper->method('findSortKeysByIds')->willReturnCallback(
            function (array $ids) {
                $all = [
                    1 => ['updated_at' => '2026-01-01 00:00:00', 'created_at' => null, 'is_pinned' => true],
                    2 => ['updated_at' => '2026-06-01 00:00:00', 'created_at' => null, 'is_pinned' => false],
                ];
                $keys = [];
                foreach ($ids as $id) {
                    if (isset($all[$id])) {
                        $keys[$id] = $all[$id];
                    }
                }
                return $keys;
            }
        );
        $this->mapper->method('findByIds')->willReturn([$pinnedOld, $freshUnpinned]);

        $result = $this->service->findByContact('c', 'user1');
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]->getId(), 'Pinned note should sort first');
        $this->assertSame(2, $result[1]->getId());
    }

    public function testFindByContactClampsAndPagesWindow(): void {
        // Three owned candidates; a limit of 1 / offset of 1 must return only the
        // second-most-recent note, proving the window is sliced on the ordered id
        // set rather than after loading every full row.
        $nc1 = new NoteContact(); $nc1->setNoteId(1); $nc1->setContactUid('c');
        $nc2 = new NoteContact(); $nc2->setNoteId(2); $nc2->setContactUid('c');
        $nc3 = new NoteContact(); $nc3->setNoteId(3); $nc3->setContactUid('c');
        $this->noteContactMapper->method('findByContactUid')->willReturn([$nc1, $nc2, $nc3]);
        $this->mapper->method('findByContact')->willReturn([]);

        $this->mapper->method('findSortKeysByIds')->willReturnCallback(
            function (array $ids) {
                $all = [
                    1 => ['updated_at' => '2026-06-03 00:00:00', 'created_at' => null, 'is_pinned' => false],
                    2 => ['updated_at' => '2026-06-02 00:00:00', 'created_at' => null, 'is_pinned' => false],
                    3 => ['updated_at' => '2026-06-01 00:00:00', 'created_at' => null, 'is_pinned' => false],
                ];
                $keys = [];
                foreach ($ids as $id) {
                    if (isset($all[$id])) {
                        $keys[$id] = $all[$id];
                    }
                }
                return $keys;
            }
        );

        // Only the windowed id (note 2) must be loaded as a full row.
        $n2 = new Note(); $n2->setId(2); $n2->setUserId('user1');
        $this->mapper->expects($this->once())
            ->method('findByIds')
            ->with([2], null)
            ->willReturn([$n2]);

        $result = $this->service->findByContact('c', 'user1', 1, 1);
        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]->getId());
    }

    public function testFindByContactRecoversLegacySharedNote(): void {
        // Regression: a note created by another user, shared with the caller,
        // and linked to the contact ONLY via the legacy contact_uid column (no
        // junction row) must still appear for the recipient. Previously the
        // legacy lookup was owner-scoped, so the note was never collected and
        // the shared-notes intersection could never recover it.
        $mapper = $this->createMock(NoteMapper::class);
        $noteContactMapper = $this->createMock(NoteContactMapper::class);
        $noteFileMapper = $this->createMock(NoteFileMapper::class);
        $noteSharingMapper = $this->createMock(NoteSharingMapper::class);
        $settingsService = $this->createMock(SettingsService::class);
        $noteTypeService = $this->createMock(NoteTypeService::class);
        $rootFolder = $this->createMock(IRootFolder::class);
        $logger = $this->createMock(LoggerInterface::class);

        $settingsService->method('isNotesPublic')->willReturn(false);
        $settingsService->method('getUserGroupIds')->willReturn([]);

        // No junction rows for this contact.
        $noteContactMapper->method('findByContactUid')->willReturn([]);
        $noteContactMapper->method('findByNoteId')->willReturn([]);
        $noteContactMapper->method('findByNoteIds')->willReturn([]);
        $noteFileMapper->method('findByNoteId')->willReturn([]);
        $noteFileMapper->method('findByNoteIds')->willReturn([]);
        $noteSharingMapper->method('findByNoteId')->willReturn([]);
        $noteSharingMapper->method('findByNoteIds')->willReturn([]);

        // The shared note, owned by someone else, linked via the legacy column.
        $shared = new Note();
        $shared->setId(42);
        $shared->setUserId('owner');

        // The legacy lookup MUST be called un-owner-scoped (null) to find it.
        $mapper->expects($this->once())
            ->method('findByContact')
            ->with('contact-x', null)
            ->willReturn([$shared]);

        // The owner-scoped sort-key lookup finds nothing (caller does not own
        // note 42); the unscoped one (after the shared intersection) returns it.
        $mapper->method('findSortKeysByIds')->willReturnCallback(
            function (array $ids, ?string $userId) {
                if ($userId === 'recipient') {
                    // Caller owns none of the candidates.
                    return [];
                }
                $keys = [];
                foreach ($ids as $id) {
                    $keys[$id] = ['updated_at' => '2026-06-01 00:00:00', 'created_at' => null, 'is_pinned' => false];
                }
                return $keys;
            }
        );

        // Only the windowed page (the shared id) is loaded as a full row,
        // unscoped.
        $mapper->method('findByIds')->willReturnCallback(
            function (array $ids, ?string $userId) use ($shared) {
                if ($userId === null && in_array(42, $ids, true)) {
                    return [$shared];
                }
                return [];
            }
        );

        // Note 42 is shared with the caller.
        $noteSharingMapper->method('findAccessibleNoteIds')->willReturn([42]);

        $service = new NoteService(
            $mapper,
            $noteContactMapper,
            $noteFileMapper,
            $noteSharingMapper,
            $settingsService,
            $noteTypeService,
            $rootFolder,
            $logger,
        );

        $result = $service->findByContact('contact-x', 'recipient');
        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]->getId());
    }

    public function testFindByContactEmpty(): void {
        $this->noteContactMapper->method('findByContactUid')->willReturn([]);
        $this->mapper->method('findByContact')->willReturn([]);

        $result = $this->service->findByContact('no-contact', 'user1');
        $this->assertEmpty($result);
    }

    public function testCreate(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (Note $n) {
                return $n->getContactUid() === 'contact-abc'
                    && $n->getAddressbookId() === 2
                    && $n->getNoteTypeId() === 3
                    && $n->getTitle() === 'Follow up'
                    && $n->getContent() === 'Call back'
                    && $n->getUserId() === 'user1'
                    && $n->getIsPinned() === false
                    && $n->getCreatedAt() instanceof DateTime
                    && $n->getUpdatedAt() instanceof DateTime;
            }))
            ->willReturnCallback(function (Note $n) {
                $n->setId(1);
                return $n;
            });

        $this->noteContactMapper->expects($this->once())
            ->method('insert')
            ->willReturnArgument(0);

        $result = $this->service->create('contact-abc', 2, 3, 'Follow up', 'Call back', 'user1');
        $this->assertSame('Follow up', $result->getTitle());
        $this->assertSame('Call back', $result->getContent());
    }

    public function testCreateWithNullContent(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (Note $n) => $n->getContent() === ''))
            ->willReturnCallback(function (Note $n) { $n->setId(2); return $n; });

        $this->noteContactMapper->method('insert')->willReturnArgument(0);

        $this->service->create('uid', 1, 1, 'Title', null, 'user1');
    }

    public function testCreatePinned(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(fn (Note $n) => $n->getIsPinned() === true))
            ->willReturnCallback(function (Note $n) { $n->setId(3); return $n; });

        $this->noteContactMapper->method('insert')->willReturnArgument(0);

        $this->service->create('uid', 1, 1, 'Title', 'Content', 'user1', true);
    }

    public function testCreateAppliesDefaultShareTargets(): void {
        $this->mapper->method('insert')
            ->willReturnCallback(function (Note $n) { $n->setId(7); return $n; });
        $this->noteContactMapper->method('insert')->willReturnArgument(0);

        $targets = [
            ['type' => 'user', 'id' => 'bob', 'name' => 'Bob', 'canEdit' => true],
            ['type' => 'group', 'id' => 'staff', 'name' => 'Staff', 'canEdit' => false],
        ];
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isNotesPublic')->willReturn(false);
        $settingsService->method('getUserGroupIds')->willReturn([]);
        $settingsService->method('getUserShareTargets')->with('user1')->willReturn($targets);
        $settingsService->method('principalExists')->willReturn(true);
        $this->settingsService = $settingsService;

        // sanitiseShareTargets normalises to {type, id, canEdit} — extraneous
        // keys (name) are dropped before persisting.
        $expected = [
            ['type' => 'user', 'id' => 'bob', 'canEdit' => true],
            ['type' => 'group', 'id' => 'staff', 'canEdit' => false],
        ];
        $this->noteSharingMapper->expects($this->once())
            ->method('syncSharing')
            ->with(7, $expected);

        $this->makeService()->create('uid', 1, 1, 'Title', 'Body', 'user1');
    }

    public function testCreateWithExplicitSharing(): void {
        $this->mapper->method('insert')
            ->willReturnCallback(function (Note $n) { $n->setId(8); return $n; });
        $this->noteContactMapper->method('insert')->willReturnArgument(0);

        $sharing = [['type' => 'user', 'id' => 'carol', 'canEdit' => true]];
        $this->noteSharingMapper->expects($this->once())
            ->method('syncSharing')
            ->with(8, $sharing);

        $this->service->create('uid', 1, 1, 'Title', 'Body', 'user1', false, [], $sharing);
    }

    public function testCreateDeduplicatesRepeatedShareTargets(): void {
        // crm_note_sharing has a UNIQUE index on
        // (note_id, shared_with_type, shared_with_id). A payload that lists the
        // same principal twice must be collapsed to a single target by
        // sanitiseShareTargets() so syncSharing()'s second insert never trips the
        // unique constraint (which would otherwise bubble up as a 500).
        $this->mapper->method('insert')
            ->willReturnCallback(function (Note $n) { $n->setId(9); return $n; });
        $this->noteContactMapper->method('insert')->willReturnArgument(0);

        $sharing = [
            ['type' => 'user', 'id' => 'bob', 'canEdit' => false],
            ['type' => 'user', 'id' => 'bob', 'canEdit' => true],
            ['type' => 'group', 'id' => 'staff', 'canEdit' => true],
            ['type' => 'group', 'id' => 'staff', 'canEdit' => false],
        ];
        // First occurrence of each (type, id) wins; duplicates are dropped.
        $expected = [
            ['type' => 'user', 'id' => 'bob', 'canEdit' => false],
            ['type' => 'group', 'id' => 'staff', 'canEdit' => true],
        ];
        $this->noteSharingMapper->expects($this->once())
            ->method('syncSharing')
            ->with(9, $expected);

        $this->service->create('uid', 1, 1, 'Title', 'Body', 'user1', false, [], $sharing);
    }

    public function testUpdateTitle(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('Old');
        $note->setContent('Content');
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mapper->expects($this->once())
            ->method('update')
            ->with($this->callback(fn (Note $n) => $n->getTitle() === 'New Title'))
            ->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', 'New Title');
        $this->assertSame('New Title', $result->getTitle());
    }

    public function testUpdateContent(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('Title');
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', null, 'New content');
        $this->assertSame('New content', $result->getContent());
    }

    public function testUpdateNotFound(): void {
        $this->mapper->method('findById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteNotFoundException::class);
        $this->service->update(999, 'user1', 'Title');
    }

    public function testUpdateMultipleFields(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('Old');
        $note->setContent('Old content');
        $note->setNoteTypeId(1);
        $note->setIsPinned(false);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', 'New', 'New content', 3, true);
        $this->assertSame('New', $result->getTitle());
        $this->assertSame('New content', $result->getContent());
        $this->assertSame(3, $result->getNoteTypeId());
        $this->assertTrue($result->getIsPinned());
    }

    public function testDelete(): void {
        $note = new Note();
        $note->setId(1);
        $note->setTitle('To delete');
        $note->setUserId('user1');

        $this->mapper->method('findById')->with(1, 'user1')->willReturn($note);
        $this->mapper->expects($this->once())->method('delete')->with($note);

        $result = $this->service->delete(1, 'user1');
        $this->assertSame('To delete', $result->getTitle());
    }

    public function testDeleteNotFound(): void {
        $this->mapper->method('findById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteNotFoundException::class);
        $this->service->delete(999, 'user1');
    }

    public function testCreateWithMultipleContacts(): void {
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Note $n) { $n->setId(10); return $n; });

        $this->noteContactMapper->expects($this->exactly(3))
            ->method('insert')
            ->willReturnArgument(0);

        $result = $this->service->create(
            'uid-1', 1, 1, 'Title', 'Body', 'user1', false,
            ['uid-1', 'uid-2', 'uid-3']
        );
        $this->assertSame(10, $result->getId());
    }

    public function testCreateDeduplicatesContactUids(): void {
        // M4: duplicate contact UIDs (and the primary repeated in the list) must
        // be collapsed before insert so the (note_id, contact_uid) unique index
        // is never violated. 'uid-1' appears 3x + primary; only 2 distinct rows.
        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Note $n) { $n->setId(11); return $n; });

        $this->noteContactMapper->expects($this->exactly(2))
            ->method('insert')
            ->willReturnArgument(0);

        $result = $this->service->create(
            'uid-1', 1, 1, 'Title', 'Body', 'user1', false,
            ['uid-1', 'uid-1', 'uid-2', 'uid-1', '']
        );
        $this->assertSame(11, $result->getId());
    }

    public function testUpdateWithContactUids(): void {
        $note = new Note();
        $note->setId(1);
        $note->setAddressbookId(2);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->noteContactMapper->expects($this->once())
            ->method('deleteByNoteId')
            ->with(1);

        $this->noteContactMapper->expects($this->exactly(2))
            ->method('insert')
            ->willReturnArgument(0);

        $this->service->update(1, 'user1', null, null, null, null, ['uid-a', 'uid-b']);
    }

    public function testDeleteCleansUpJunctions(): void {
        $note = new Note();
        $note->setId(5);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mapper->method('delete');

        $this->noteContactMapper->expects($this->once())
            ->method('deleteByNoteId')
            ->with(5);

        $this->noteFileMapper->expects($this->once())
            ->method('deleteByNoteId')
            ->with(5);

        $this->noteSharingMapper->expects($this->once())
            ->method('deleteByNoteId')
            ->with(5);

        $this->service->delete(5, 'user1');
    }

    // ── Authorization: owner vs write-share recipient ──────────────────────────

    public function testOwnerCanUpdate(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->with(1, 'owner')->willReturn($note);
        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $this->service->update(1, 'owner', 'Edited');
    }

    public function testWriteShareRecipientCanEditContent(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        // Not the owner — findById(user) throws, then accessible/writable checks.
        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([1]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

        $this->makeService()->update(1, 'editor', 'Edited by editor');
    }

    public function testReadOnlyShareRecipientCannotEdit(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]); // can read
        $sharing->method('findWritableNoteIds')->willReturn([]);     // cannot write
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteForbiddenException::class);
        $this->makeService()->update(1, 'reader', 'Sneaky edit');
    }

    public function testWriteShareRecipientCannotRewriteSharing(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([1]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        // A non-owner editor must NOT be able to rewrite the ACL.
        $sharing->expects($this->never())->method('syncSharing');
        $this->noteSharingMapper = $sharing;

        $this->mapper->method('update')->willReturnArgument(0);

        $this->makeService()->update(
            1, 'editor', 'Edited', null, null, null, null,
            [['type' => 'user', 'id' => 'editor', 'canEdit' => true]],
        );
    }

    public function testOwnerCanRewriteSharing(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->with(1, 'owner')->willReturn($note);
        $this->mapper->method('update')->willReturnArgument(0);

        $newSharing = [['type' => 'user', 'id' => 'bob', 'canEdit' => true]];
        $this->noteSharingMapper->expects($this->once())
            ->method('syncSharing')
            ->with(1, $newSharing);

        $this->service->update(1, 'owner', 'Edited', null, null, null, null, $newSharing);
    }

    public function testReadOnlyShareRecipientCannotDelete(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $this->mapper->expects($this->never())->method('delete');

        $this->expectException(NoteForbiddenException::class);
        $this->makeService()->delete(1, 'reader');
    }

    // ── File attachment IDOR validation ────────────────────────────────────────

    public function testAddFileValidatesOwnership(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mockUserFolderWithFile(42, '/Documents/file.pdf');

        $this->noteFileMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (NoteFile $nf) {
                return $nf->getNoteId() === 1
                    && $nf->getFileId() === 42
                    && $nf->getFilePath() === '/Documents/file.pdf'
                    && $nf->getUserId() === 'user1';
            }))
            ->willReturnArgument(0);

        $result = $this->service->addFile(1, 42, '/Documents/file.pdf', 'user1');
        $this->assertSame(1, $result->getId());
    }

    public function testAddFileRejectsUnownedFileId(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        // The claimed file id is not accessible in the caller's user folder.
        $this->mockEmptyUserFolder();

        $this->noteFileMapper->expects($this->never())->method('insert');

        $this->expectException(NoteForbiddenException::class);
        $this->service->addFile(1, 999, '/Documents/secret.pdf', 'user1');
    }

    public function testAddFileRejectsNonOwnerWriteShareRecipient(): void {
        // GRUMPY DEV #3: a write-share recipient may edit content, but must NOT
        // attach a file from their own storage — the stored path would be
        // unresolvable to the note owner. Attaching is owner-only.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([1]); // can edit content
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        // The picker must never run for a non-owner, and nothing is inserted.
        $this->rootFolder->expects($this->never())->method('getUserFolder');
        $this->noteFileMapper->expects($this->never())->method('insert');

        $this->expectException(NoteForbiddenException::class);
        $this->makeService()->addFile(1, 42, '/Documents/recipient-file.pdf', 'editor');
    }

    public function testAddFileResolvesAgainstNoteOwnerStorage(): void {
        // GRUMPY DEV #3: attachments are resolved in (and stored relative to) the
        // note OWNER's storage, so the persisted row belongs to the owner — even
        // when, in public mode, a different caller performs the attach.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        // Public mode lets any caller attach, but resolution still targets owner.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('isNotesPublic')->willReturn(true);
        $settings->method('getUserGroupIds')->willReturn([]);
        $this->settingsService = $settings;

        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $node = $this->createMock(Node::class);
        $node->method('getId')->willReturn(42);
        $node->method('getPath')->willReturn('/owner/files/Documents/file.pdf');
        $folder = $this->createMock(Folder::class);
        $folder->method('getById')->with(42)->willReturn([$node]);
        $folder->method('getRelativePath')->willReturn('/Documents/file.pdf');
        // Resolution must target the OWNER's user folder, not the caller's.
        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('owner')
            ->willReturn($folder);

        $this->noteFileMapper->expects($this->once())
            ->method('insert')
            ->with($this->callback(function (NoteFile $nf) {
                return $nf->getNoteId() === 1
                    && $nf->getFileId() === 42
                    && $nf->getFilePath() === '/Documents/file.pdf'
                    // Attachment row belongs to the owner, not the caller.
                    && $nf->getUserId() === 'owner';
            }))
            ->willReturnArgument(0);

        $this->makeService()->addFile(1, 42, '/Documents/file.pdf', 'somebody-else');
    }

    public function testAddFileNotFound(): void {
        $this->mapper->method('findById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteNotFoundException::class);
        $this->service->addFile(999, 1, '/file.txt', 'user1');
    }

    public function testAddFileSwallowsDuplicateConstraintViolation(): void {
        // M3: a concurrent attach of the same (note_id, file_path) hits the
        // crm_nf_path_unique index. The service must treat it as already-attached
        // (idempotent) rather than letting the DBAL exception become a 500.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mockUserFolderWithFile(42, '/Documents/file.pdf');

        $dup = new \OCP\DB\Exception('duplicate');
        $dup->setReason(\OCP\DB\Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);
        $this->noteFileMapper->method('insert')->willThrowException($dup);

        // Should not throw — the duplicate is swallowed and the note returned.
        $result = $this->service->addFile(1, 42, '/Documents/file.pdf', 'user1');
        $this->assertSame(1, $result->getId());
    }

    public function testAddFileRethrowsNonUniqueDbException(): void {
        // A non-unique DB failure must still propagate (and surface as a 500 via
        // the controller's ErrorHandler), not be silently swallowed.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mockUserFolderWithFile(42, '/Documents/file.pdf');

        $other = new \OCP\DB\Exception('connection lost');
        $other->setReason(99);
        $this->noteFileMapper->method('insert')->willThrowException($other);

        $this->expectException(\OCP\DB\Exception::class);
        $this->service->addFile(1, 42, '/Documents/file.pdf', 'user1');
    }

    public function testRemoveFile(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);

        $nf = new NoteFile();
        $nf->setId(10);
        $nf->setNoteId(1);
        $nf->setFileId(42);
        $nf->setFilePath('/test.txt');
        $nf->setUserId('user1');

        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->with(1)->willReturn([$nf]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $fileMapper->expects($this->once())->method('delete')->with($nf);
        $this->noteFileMapper = $fileMapper;

        $this->makeService()->removeFile(10, 1, 'user1');
    }

    public function testRemoveFileRejectsNonOwnerWriteShareRecipient(): void {
        // GRUMPY DEV #2 (round 2): file attachments can only be ADDED by the note
        // owner (addFile is owner-only), so detaching is symmetrically owner-only.
        // A write-share recipient — who can never attach a file — must not be able
        // to permanently destroy the owner's attachment rows. Removing is owner-
        // only; the recipient gets a 403 and nothing is deleted.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([1]); // can edit content
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $nf = new NoteFile();
        $nf->setId(10);
        $nf->setNoteId(1);
        $nf->setFilePath('/owner-file.pdf');
        $nf->setUserId('owner');

        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->willReturn([$nf]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        // The owner's attachment must NOT be deleted by a recipient.
        $fileMapper->expects($this->never())->method('delete');
        $this->noteFileMapper = $fileMapper;

        $this->expectException(NoteForbiddenException::class);
        $this->makeService()->removeFile(10, 1, 'editor');
    }

    public function testRemoveFileAllowedForOwnerInPublicMode(): void {
        // GRUMPY DEV #2 (round 2, control): in global public mode every caller is
        // effectively an owner (mirroring addFile), so detaching is permitted.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('isNotesPublic')->willReturn(true);
        $settings->method('getUserGroupIds')->willReturn([]);
        $this->settingsService = $settings;

        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $nf = new NoteFile();
        $nf->setId(10);
        $nf->setNoteId(1);
        $nf->setFilePath('/owner-file.pdf');
        $nf->setUserId('owner');

        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->willReturn([$nf]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $fileMapper->expects($this->once())->method('delete')->with($nf);
        $this->noteFileMapper = $fileMapper;

        $this->makeService()->removeFile(10, 1, 'anybody');
    }

    public function testRemoveFileThrowsWhenNoteFileIdDoesNotBelongToNote(): void {
        // The note exists and is owned by the caller, but the supplied
        // noteFileId matches no file attached to it. removeFile must report a
        // 404 (NoteNotFoundException) instead of silently succeeding.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);

        $nf = new NoteFile();
        $nf->setId(10);
        $nf->setNoteId(1);
        $nf->setFilePath('/test.txt');
        $nf->setUserId('user1');

        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->with(1)->willReturn([$nf]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        // Nothing matches noteFileId 999 → delete must never be called.
        $fileMapper->expects($this->never())->method('delete');
        $this->noteFileMapper = $fileMapper;

        $this->expectException(NoteNotFoundException::class);
        $this->makeService()->removeFile(999, 1, 'user1');
    }

    public function testRemoveFileNotFound(): void {
        $this->mapper->method('findById')
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteNotFoundException::class);
        $this->service->removeFile(1, 999, 'user1');
    }

    public function testEnrichNotePopulatesContactsAndFiles(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);

        $nc = new NoteContact();
        $nc->setId(1);
        $nc->setNoteId(1);
        $nc->setContactUid('uid-a');
        $nc->setAddressbookId(2);

        $nf = new NoteFile();
        $nf->setId(1);
        $nf->setNoteId(1);
        $nf->setFileId(50);
        $nf->setFilePath('/test.pdf');
        $nf->setUserId('user1');

        $contactMapper = $this->createMock(NoteContactMapper::class);
        $contactMapper->method('findByNoteId')->with(1)->willReturn([$nc]);
        $contactMapper->method('findByNoteIds')->willReturn([]);
        $this->noteContactMapper = $contactMapper;

        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->with(1)->willReturn([$nf]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper = $fileMapper;

        $result = $this->makeService()->find(1, 'user1');

        $this->assertCount(1, $result->getContacts());
        $this->assertSame('uid-a', $result->getContacts()[0]['contactUid']);
        $this->assertCount(1, $result->getFiles());
        $this->assertSame('/test.pdf', $result->getFiles()[0]['filePath']);
    }

    // ── Note-type ownership validation ──────────────────────────────────────────

    public function testCreateRejectsForeignNoteType(): void {
        // The note type is not visible to the caller — create must 400, not insert.
        $noteTypeService = $this->createMock(NoteTypeService::class);
        $noteTypeService->method('find')
            ->willThrowException(new NoteTypeNotFoundException('nope'));
        $this->noteTypeService = $noteTypeService;

        $this->mapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->makeService()->create('uid', 1, 999, 'Title', 'Body', 'user1');
    }

    public function testUpdateRejectsForeignNoteType(): void {
        $note = new Note();
        $note->setId(1);
        $note->setUserId('user1');
        $note->setNoteTypeId(1);

        $this->mapper->method('findById')->willReturn($note);

        $noteTypeService = $this->createMock(NoteTypeService::class);
        // The change to type 999 is rejected because it is not visible.
        $noteTypeService->method('find')
            ->willThrowException(new NoteTypeNotFoundException('nope'));
        $this->noteTypeService = $noteTypeService;

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteValidationException::class);
        $this->makeService()->update(1, 'user1', null, null, 999);
    }

    public function testUpdateValidatesNoteTypeAgainstOwnerNotEditor(): void {
        // GRUMPY DEV #4: when a write-share recipient changes the note type, the
        // chosen type must be validated against the OWNER's visibility, not the
        // editor's — otherwise the editor could set a private type the owner
        // cannot resolve, blanking the badge for everyone else.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');
        $note->setNoteTypeId(1);

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([1]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $noteTypeService = $this->createMock(NoteTypeService::class);
        // The note-type visibility check must be performed for the OWNER.
        $noteTypeService->expects($this->atLeastOnce())
            ->method('find')
            ->with(7, 'owner')
            ->willReturn(new NoteType());
        $this->noteTypeService = $noteTypeService;

        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->makeService()->update(1, 'editor', null, null, 7);
        $this->assertSame(7, $result->getNoteTypeId());
    }

    public function testUpdateRejectsNoteTypeNotVisibleToOwner(): void {
        // GRUMPY DEV #4 (negative): an editor cannot set a type the owner can't see.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');
        $note->setNoteTypeId(1);

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([1]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $noteTypeService = $this->createMock(NoteTypeService::class);
        $noteTypeService->method('find')
            ->with(7, 'owner')
            ->willThrowException(new NoteTypeNotFoundException('owner cannot see this type'));
        $this->noteTypeService = $noteTypeService;

        $this->mapper->expects($this->never())->method('update');

        $this->expectException(NoteValidationException::class);
        $this->makeService()->update(1, 'editor', null, null, 7);
    }

    // ── Sharing/ACL visibility for non-owner recipients ─────────────────────────

    public function testNonOwnerRecipientSeesOnlyOwnShareEntry(): void {
        // GRUMPY DEV #2: a read-only recipient must not learn the full ACL. The
        // serialized 'sharing' list is reduced to the viewer's own entry (their
        // user share plus any group share they belong to), and audit fields are
        // hidden.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $own = new \OCA\CrmNotes\Db\NoteSharing();
        $own->setNoteId(1);
        $own->setSharedWithType('user');
        $own->setSharedWithId('reader');
        $own->setCanEdit(false);

        $other = new \OCA\CrmNotes\Db\NoteSharing();
        $other->setNoteId(1);
        $other->setSharedWithType('user');
        $other->setSharedWithId('someone-else');
        $other->setCanEdit(true);

        $group = new \OCA\CrmNotes\Db\NoteSharing();
        $group->setNoteId(1);
        $group->setSharedWithType('group');
        $group->setSharedWithId('staff');
        $group->setCanEdit(false);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('isNotesPublic')->willReturn(false);
        $settings->method('getUserGroupIds')->with('reader')->willReturn(['staff']);
        $this->settingsService = $settings;

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([]);
        $sharing->method('findByNoteId')->willReturn([$own, $other, $group]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $result = $this->makeService()->find(1, 'reader');
        $json = $result->jsonSerialize();

        // Audit/identity fields hidden for a non-owner recipient.
        $this->assertArrayNotHasKey('userId', $json);
        $this->assertArrayNotHasKey('createdBy', $json);

        // Only the reader's own user share and the group they belong to remain —
        // the 'someone-else' entry is not exposed.
        $ids = array_map(fn ($s) => $s['sharedWithId'], $json['sharing']);
        sort($ids);
        $this->assertSame(['reader', 'staff'], $ids);
    }

    public function testSingleNoteNonOwnerResolvesGroupsOncePerEnrich(): void {
        // GRUMPY DEV #4.2: enrichNote() must resolve the caller's group
        // memberships itself and hand them to applyAuditVisibility(), mirroring
        // the batch path, rather than letting applyAuditVisibility() re-query
        // getUserGroupIds() a second time. A non-owner find() therefore performs
        // exactly two lookups for the whole request — one for the share
        // access-control check, and exactly one inside enrichNote() that is
        // reused for the recipient-aware sharing filter (not two: the relocated
        // resolution must not be duplicated inside applyAuditVisibility()).
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $own = new \OCA\CrmNotes\Db\NoteSharing();
        $own->setNoteId(1);
        $own->setSharedWithType('user');
        $own->setSharedWithId('reader');
        $own->setCanEdit(false);

        $settings = $this->createMock(SettingsService::class);
        $settings->method('isNotesPublic')->willReturn(false);
        $settings->expects($this->exactly(2))
            ->method('getUserGroupIds')
            ->with('reader')
            ->willReturn(['staff']);
        $this->settingsService = $settings;

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([]);
        $sharing->method('findByNoteId')->willReturn([$own]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $result = $this->makeService()->find(1, 'reader');
        $json = $result->jsonSerialize();

        // Sanity: the recipient-aware filter still produced the viewer's own entry,
        // proving applyAuditVisibility() received valid group memberships.
        $ids = array_map(fn ($s) => $s['sharedWithId'], $json['sharing']);
        $this->assertSame(['reader'], $ids);
    }

    public function testOwnerSeesFullShareList(): void {
        // GRUMPY DEV #2 (control): the owner still sees the complete ACL.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->with(1, 'owner')->willReturn($note);

        $a = new \OCA\CrmNotes\Db\NoteSharing();
        $a->setNoteId(1); $a->setSharedWithType('user'); $a->setSharedWithId('reader'); $a->setCanEdit(false);
        $b = new \OCA\CrmNotes\Db\NoteSharing();
        $b->setNoteId(1); $b->setSharedWithType('user'); $b->setSharedWithId('someone-else'); $b->setCanEdit(true);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([]);
        $sharing->method('findWritableNoteIds')->willReturn([]);
        $sharing->method('findByNoteId')->willReturn([$a, $b]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $result = $this->makeService()->find(1, 'owner');
        $json = $result->jsonSerialize();

        $this->assertArrayHasKey('userId', $json);
        $this->assertCount(2, $json['sharing']);
    }

    // ── File path / ID disclosure to share recipients (GRUMPY DEV #2) ──────────

    private function makeNoteFile(int $id, int $noteId, ?int $fileId, string $path): NoteFile {
        $nf = new NoteFile();
        $nf->setId($id);
        $nf->setNoteId($noteId);
        if ($fileId !== null) {
            $nf->setFileId($fileId);
        }
        $nf->setFilePath($path);
        $nf->setUserId('owner');
        return $nf;
    }

    public function testOwnerSeesFullFileRecord(): void {
        // The owner sees the complete attachment record: owner-relative path and
        // internal fileId.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->with(1, 'owner')->willReturn($note);

        $file = $this->makeNoteFile(7, 1, 42, 'Clients/AcmeCorp/2026-contract.pdf');
        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->willReturn([$file]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper = $fileMapper;

        $json = $this->makeService()->find(1, 'owner')->jsonSerialize();

        $this->assertCount(1, $json['files']);
        $f = $json['files'][0];
        $this->assertSame('Clients/AcmeCorp/2026-contract.pdf', $f['filePath']);
        $this->assertSame(42, $f['fileId']);
    }

    public function testShareRecipientFileRecordHidesPathAndId(): void {
        // A plain (read-only) share recipient must NOT learn the owner's private
        // folder structure or the internal file id — only a basename label.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $file = $this->makeNoteFile(7, 1, 42, 'Clients/AcmeCorp/2026-contract.pdf');
        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->willReturn([$file]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper = $fileMapper;

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $json = $this->makeService()->find(1, 'recipient')->jsonSerialize();

        $this->assertCount(1, $json['files']);
        $f = $json['files'][0];
        // No owner directory layout, no internal file id leaked.
        $this->assertArrayNotHasKey('filePath', $f);
        $this->assertArrayNotHasKey('fileId', $f);
        // Only a non-identifying basename label remains.
        $this->assertSame('2026-contract.pdf', $f['name']);
        // Sanity: audit fields are also withheld, confirming non-owner view.
        $this->assertArrayNotHasKey('userId', $json);
    }

    public function testShareRecipientFileRecordWithEmptyPathYieldsEmptyName(): void {
        // GRUMPY DEV #1 (round 1): when the stored file_path is empty, the
        // recipient-filtered record carries name => '' and still drops
        // filePath/fileId. This is the exact server payload that makes the
        // frontend fallback `f.name || f.filePath.split(...)` reachable with a
        // missing filePath key — the Vue templates now guard against it, and
        // this test pins the server contract that produces the condition.
        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');

        $this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        // An attachment row whose path is empty (basename('') === '').
        $file = $this->makeNoteFile(7, 1, 42, '');
        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->willReturn([$file]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper = $fileMapper;

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([1]);
        $sharing->method('findWritableNoteIds')->willReturn([]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $json = $this->makeService()->find(1, 'recipient')->jsonSerialize();

        $this->assertCount(1, $json['files']);
        $f = $json['files'][0];
        $this->assertArrayNotHasKey('filePath', $f);
        $this->assertArrayNotHasKey('fileId', $f);
        // The empty-basename label survives as an empty string; the frontend
        // fileLabel() helper falls back to a generic "Attachment" label.
        $this->assertSame('', $f['name']);
    }

    public function testPublicModeExposesFullFileRecord(): void {
        // In global public mode everyone is effectively an owner, so the full
        // file record (path + id) is exposed.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('isNotesPublic')->willReturn(true);
        $settings->method('getUserGroupIds')->willReturn([]);
        $this->settingsService = $settings;

        $note = new Note();
        $note->setId(1);
        $note->setUserId('owner');
        $this->mapper->method('findByIdPublic')->with(1)->willReturn($note);

        $file = $this->makeNoteFile(7, 1, 42, 'Clients/AcmeCorp/2026-contract.pdf');
        $fileMapper = $this->createMock(NoteFileMapper::class);
        $fileMapper->method('findByNoteId')->willReturn([$file]);
        $fileMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper = $fileMapper;

        $json = $this->makeService()->find(1, 'anyone')->jsonSerialize();

        $this->assertSame('Clients/AcmeCorp/2026-contract.pdf', $json['files'][0]['filePath']);
        $this->assertSame(42, $json['files'][0]['fileId']);
    }

    // ── find() in public mode maps missing id to 404, not 500 ────────────────────

    public function testFindPublicModeMissingIdThrowsNotFound(): void {
        // GRUMPY DEV #4.1: in public mode find() calls findByIdPublic(), which
        // throws DoesNotExistException for an unknown id. That must surface as a
        // NoteNotFoundException (404), not escape to the generic 500 handler.
        $settings = $this->createMock(SettingsService::class);
        $settings->method('isNotesPublic')->willReturn(true);
        $settings->method('getUserGroupIds')->willReturn([]);
        $this->settingsService = $settings;

        $this->mapper->method('findByIdPublic')
            ->with(999)
            ->willThrowException(new DoesNotExistException('Not found'));

        $this->expectException(NoteNotFoundException::class);
        $this->makeService()->find(999, 'anyone');
    }

    public function testFindPostShareFallbackMissingIdThrowsNotFound(): void {
        // GRUMPY DEV #4.1: the private-mode post-share fallback also calls
        // findByIdPublic() (after confirming the id is in the accessible set).
        // A race in which the row vanishes before the second load throws
        // DoesNotExistException, which must also map to a 404.
        $note = new Note();
        $note->setId(1);

        $sharing = $this->createMock(NoteSharingMapper::class);
        $sharing->method('findAccessibleNoteIds')->willReturn([5]);
        $sharing->method('findByNoteId')->willReturn([]);
        $sharing->method('findByNoteIds')->willReturn([]);
        $this->noteSharingMapper = $sharing;

        $this->mapper->method('findById')
            ->willThrowException(new DoesNotExistException('not owned'));
        $this->mapper->method('findByIdPublic')
            ->with(5)
            ->willThrowException(new DoesNotExistException('vanished'));

        $this->expectException(NoteNotFoundException::class);
        $this->makeService()->find(5, 'recipient');
    }

    // ── Over-length junction contact UIDs yield a clean 400 ──────────────────────

    public function testCreateRejectsOverLengthJunctionContactUid(): void {
        // GRUMPY DEV #4.2: an over-length entry in the additional contactUids
        // array must be rejected with a NoteValidationException (400) before any
        // row is inserted — never an opaque DB-truncation 500, and never an
        // orphan note left behind from a half-completed create.
        $longUid = str_repeat('x', 256);

        $this->mapper->expects($this->never())->method('insert');
        $this->noteContactMapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->create(
            'uid-1', 1, 1, 'Title', 'Body', 'user1', false,
            ['uid-2', $longUid]
        );
    }

    public function testCreateAcceptsMaxLengthJunctionContactUid(): void {
        // Boundary: exactly 255 characters is allowed.
        $maxUid = str_repeat('y', 255);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Note $n) { $n->setId(20); return $n; });
        $this->noteContactMapper->expects($this->exactly(2))
            ->method('insert')
            ->willReturnArgument(0);

        $result = $this->service->create(
            'uid-1', 1, 1, 'Title', 'Body', 'user1', false,
            [$maxUid]
        );
        $this->assertSame(20, $result->getId());
    }

    public function testUpdateRejectsOverLengthJunctionContactUid(): void {
        // GRUMPY DEV #4.2: the same length validation applies to update()'s
        // synced contactUids, and must run before the note row or the junction
        // table is mutated.
        $longUid = str_repeat('z', 256);

        $note = new Note();
        $note->setId(1);
        $note->setAddressbookId(2);
        $note->setUserId('user1');

        $this->mapper->method('findById')->willReturn($note);
        $this->mapper->expects($this->never())->method('update');
        $this->noteContactMapper->expects($this->never())->method('deleteByNoteId');
        $this->noteContactMapper->expects($this->never())->method('insert');

        $this->expectException(NoteValidationException::class);
        $this->service->update(1, 'user1', null, null, null, null, ['ok-uid', $longUid]);
    }
}
