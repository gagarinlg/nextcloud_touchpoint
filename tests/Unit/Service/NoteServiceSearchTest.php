<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Service;

use OCA\Touchpoint\Db\Note;
use OCA\Touchpoint\Db\NoteContactMapper;
use OCA\Touchpoint\Db\NoteFileMapper;
use OCA\Touchpoint\Db\NoteMapper;
use OCA\Touchpoint\Db\NoteSharingMapper;
use OCA\Touchpoint\Db\NoteType;
use OCA\Touchpoint\Service\NoteService;
use OCA\Touchpoint\Service\NoteTypeService;
use OCA\Touchpoint\Service\SettingsService;
use OCP\Files\IRootFolder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for NoteService::search().
 *
 * Covers T2 acceptance criteria:
 * (a) blank term returns [] without calling mapper
 * (b) whitespace-only term returns [] without calling mapper
 * (c) public mode returns [] without calling mapper (isNotesPublic() true branch)
 * (d) limit=500 passed to service results in mapper receiving limit=200 (clamp)
 * (e) private mode calls getUserGroupIds() + findAccessibleNoteIds() + searchAccessiblePage()
 *     with correct args; enrichNotes() receives the SAME $userId used to scope the query
 * (f) enrichNotes() is NOT called when public mode is active (returns before enrichNotes call)
 * (g) enrichNotes() is NOT called for blank/whitespace terms
 * (h) sort is normalised (unknown sort keyword defaults to 'newest')
 */
class NoteServiceSearchTest extends TestCase {

    /** @var NoteMapper&MockObject */
    private NoteMapper $mapper;
    /** @var NoteContactMapper&MockObject */
    private NoteContactMapper $noteContactMapper;
    /** @var NoteFileMapper&MockObject */
    private NoteFileMapper $noteFileMapper;
    /** @var NoteSharingMapper&MockObject */
    private NoteSharingMapper $noteSharingMapper;
    /** @var SettingsService&MockObject */
    private SettingsService $settingsService;
    /** @var NoteTypeService&MockObject */
    private NoteTypeService $noteTypeService;
    /** @var IRootFolder&MockObject */
    private IRootFolder $rootFolder;
    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->mapper              = $this->createMock(NoteMapper::class);
        $this->noteContactMapper   = $this->createMock(NoteContactMapper::class);
        $this->noteFileMapper      = $this->createMock(NoteFileMapper::class);
        $this->noteSharingMapper   = $this->createMock(NoteSharingMapper::class);
        $this->settingsService     = $this->createMock(SettingsService::class);
        $this->noteTypeService     = $this->createMock(NoteTypeService::class);
        $this->rootFolder          = $this->createMock(IRootFolder::class);
        $this->logger              = $this->createMock(LoggerInterface::class);

        // Defaults: private mode, no groups, no shares.
        $this->settingsService->method('isNotesPublic')->willReturn(false);
        $this->settingsService->method('getUserGroupIds')->willReturn([]);
        $this->settingsService->method('getUserShareTargets')->willReturn([]);
        $this->settingsService->method('principalExists')->willReturn(true);
        $this->noteSharingMapper->method('findAccessibleNoteIds')->willReturn([]);
        $this->noteSharingMapper->method('findWritableNoteIds')->willReturn([]);
        $this->noteSharingMapper->method('findByNoteId')->willReturn([]);
        $this->noteSharingMapper->method('findByNoteIds')->willReturn([]);

        // enrichNotes() dependencies — empty by default so enrichment completes.
        $this->noteContactMapper->method('findByNoteId')->willReturn([]);
        $this->noteContactMapper->method('findByNoteIds')->willReturn([]);
        $this->noteFileMapper->method('findByNoteId')->willReturn([]);
        $this->noteFileMapper->method('findByNoteIds')->willReturn([]);

        $this->noteTypeService->method('find')->willReturn(new NoteType());
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

    // -------------------------------------------------------------------------
    // (a) Blank term returns [] — mapper never called
    // -------------------------------------------------------------------------

    public function testSearchBlankTermReturnsEmpty(): void {
        $this->mapper->expects($this->never())->method('searchAccessiblePage');

        $result = $this->makeService()->search('user1', '');
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // (b) Whitespace-only term returns [] — mapper never called
    // -------------------------------------------------------------------------

    public function testSearchWhitespaceOnlyTermReturnsEmpty(): void {
        $this->mapper->expects($this->never())->method('searchAccessiblePage');

        $result = $this->makeService()->search('user1', '   ');
        $this->assertSame([], $result);
    }

    public function testSearchTabOnlyTermReturnsEmpty(): void {
        $this->mapper->expects($this->never())->method('searchAccessiblePage');

        $result = $this->makeService()->search('user1', "\t\n ");
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // (c) Public mode returns [] — mapper never called
    // -------------------------------------------------------------------------

    public function testSearchPublicModeReturnsEmpty(): void {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isNotesPublic')->willReturn(true);
        // getUserGroupIds and findAccessibleNoteIds must NOT be called in public mode.
        $settingsService->expects($this->never())->method('getUserGroupIds');

        $this->noteSharingMapper->expects($this->never())->method('findAccessibleNoteIds');
        $this->mapper->expects($this->never())->method('searchAccessiblePage');

        $service = new NoteService(
            $this->mapper,
            $this->noteContactMapper,
            $this->noteFileMapper,
            $this->noteSharingMapper,
            $settingsService,
            $this->noteTypeService,
            $this->rootFolder,
            $this->logger,
        );

        $result = $service->search('user1', 'hello');
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Service-level term-length guard (the ONLY length defense for callers that
    // bypass NoteController's HTTP-400 guard — i.e. NoteSearchProvider via
    // Unified Search). NoteService::search() returns [] for an over-length term
    // and must never reach the mapper, preventing a LIKE '%…50000 chars…%'
    // full-table scan. These cases also lock the off-by-one at the boundary.
    // -------------------------------------------------------------------------

    public function testSearchOverMaxLengthTermReturnsEmptyAndSkipsMapper(): void {
        // One character over the limit must be rejected before any DB work.
        $term = str_repeat('a', NoteMapper::MAX_SEARCH_TERM_LENGTH + 1);

        $this->mapper->expects($this->never())->method('searchAccessiblePage');
        // enrichNotes() must never run either — the guard returns before it.
        $this->noteContactMapper->expects($this->never())->method('findByNoteIds');
        $this->noteFileMapper->expects($this->never())->method('findByNoteIds');

        $result = $this->makeService()->search('user1', $term);
        $this->assertSame([], $result);
    }

    public function testSearchAtExactlyMaxLengthTermReachesMapper(): void {
        // A term of exactly MAX_SEARCH_TERM_LENGTH characters is allowed through:
        // the guard rejects only terms STRICTLY longer than the limit. This locks
        // the boundary so a future '>' -> '>=' slip is caught.
        $term = str_repeat('a', NoteMapper::MAX_SEARCH_TERM_LENGTH);

        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], $term, 50, 0, 'newest')
            ->willReturn([]);

        $result = $this->makeService()->search('user1', $term);
        $this->assertSame([], $result);
    }

    public function testSearchOverMaxLengthMeasuredInCharactersNotBytes(): void {
        // mb_strlen counts characters, not bytes: a multibyte term that is
        // MAX_SEARCH_TERM_LENGTH CHARACTERS long (but more bytes) must still be
        // allowed through, proving the guard is character-based.
        $term = str_repeat('é', NoteMapper::MAX_SEARCH_TERM_LENGTH);

        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], $term, 50, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', $term);
    }

    // -------------------------------------------------------------------------
    // (d) limit=500 clamped to 200
    // -------------------------------------------------------------------------

    public function testSearchLimitClampsToMax(): void {
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with(
                'user1',
                [],      // sharedIds (no shares in setUp)
                'hello', // term
                200,     // clamped from 500
                0,
                'newest'
            )
            ->willReturn([]);

        $this->makeService()->search('user1', 'hello', 500, 0, 'newest');
    }

    public function testSearchLimitClampedToMin(): void {
        // Negative limit must be clamped to 1.
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'hello', 1, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', 'hello', -5, 0, 'newest');
    }

    public function testSearchDefaultLimitIs50(): void {
        // null limit → default 50
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'hello', 50, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', 'hello', null, null, 'newest');
    }

    public function testSearchNegativeOffsetClampedToZero(): void {
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'hello', 50, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', 'hello', null, -10, 'newest');
    }

    // -------------------------------------------------------------------------
    // (e) Private mode calls getUserGroupIds, findAccessibleNoteIds, searchAccessiblePage;
    //     enrichNotes receives the SAME $userId used to scope the mapper query.
    //     We verify identity by confirming the mapper is called with 'user1' and
    //     the enrichment dependency (findByNoteIds) is called for that same user's
    //     note set — both paths use the single $userId variable.
    // -------------------------------------------------------------------------

    public function testSearchPrivateModeCallsCorrectHelpers(): void {
        $note = new Note();
        $note->setId(42);
        $note->setUserId('user1');

        // Use fresh mocks so setUp() defaults do not interfere with strict
        // with() expectations.
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isNotesPublic')->willReturn(false);
        // getUserGroupIds is called exactly twice:
        //   Call 1: in search() to resolve $groupIds for findAccessibleNoteIds().
        //   Call 2: in enrichNotes() → applyAuditVisibility() for the batch-audit pass.
        // If enrichNotes() is refactored to accept pre-resolved groupIds as a
        // parameter (removing the second call), update this assertion to exactly(1)
        // and add the groupIds argument to the enrichNotes() signature. The count
        // is intentional coupling to the current two-call flow, not an error.
        $settingsService->expects($this->exactly(2))
            ->method('getUserGroupIds')
            ->with('user1')
            ->willReturn(['group-a']);

        $noteSharingMapper = $this->createMock(NoteSharingMapper::class);
        $noteSharingMapper->expects($this->once())
            ->method('findAccessibleNoteIds')
            ->with('user1', ['group-a'])
            ->willReturn([7, 8]);
        $noteSharingMapper->method('findByNoteIds')->willReturn([]);

        $mapper = $this->createMock(NoteMapper::class);
        $mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [7, 8], 'foo', 50, 0, 'newest')
            ->willReturn([$note]);

        // enrichNotes is called: verify findByNoteIds is invoked (it is part of
        // the batch-enrichment path, proving enrichNotes ran for the returned page).
        $noteContactMapper = $this->createMock(NoteContactMapper::class);
        $noteContactMapper->expects($this->once())
            ->method('findByNoteIds')
            ->with([42])
            ->willReturn([]);

        $noteFileMapper = $this->createMock(NoteFileMapper::class);
        $noteFileMapper->expects($this->once())
            ->method('findByNoteIds')
            ->with([42])
            ->willReturn([]);

        $service = new NoteService(
            $mapper,
            $noteContactMapper,
            $noteFileMapper,
            $noteSharingMapper,
            $settingsService,
            $this->noteTypeService,
            $this->rootFolder,
            $this->logger,
        );

        $result = $service->search('user1', 'foo', null, null, 'newest');
        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]->getId());
    }

    // -------------------------------------------------------------------------
    // (f) enrichNotes is NOT called when public mode is active
    //     (the method returns before reaching enrichNotes)
    // -------------------------------------------------------------------------

    public function testSearchPublicModeDoesNotCallEnrichNotes(): void {
        $settingsService = $this->createMock(SettingsService::class);
        $settingsService->method('isNotesPublic')->willReturn(true);

        // If enrichNotes were called it would trigger findByNoteIds; assert it is not.
        $this->noteContactMapper->expects($this->never())->method('findByNoteIds');
        $this->noteFileMapper->expects($this->never())->method('findByNoteIds');
        $this->noteSharingMapper->expects($this->never())->method('findByNoteIds');

        $service = new NoteService(
            $this->mapper,
            $this->noteContactMapper,
            $this->noteFileMapper,
            $this->noteSharingMapper,
            $settingsService,
            $this->noteTypeService,
            $this->rootFolder,
            $this->logger,
        );

        $result = $service->search('user1', 'hello');
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // (g) enrichNotes is NOT called for blank/whitespace terms
    // -------------------------------------------------------------------------

    public function testSearchBlankTermDoesNotCallEnrichNotes(): void {
        $this->noteContactMapper->expects($this->never())->method('findByNoteIds');
        $this->noteFileMapper->expects($this->never())->method('findByNoteIds');

        $result = $this->makeService()->search('user1', '');
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // (h) sort is normalised
    // -------------------------------------------------------------------------

    public function testSearchNormalisesUnknownSortToNewest(): void {
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'term', 50, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', 'term', null, null, 'bogus');
    }

    public function testSearchPassesOldestSort(): void {
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'term', 50, 0, 'oldest')
            ->willReturn([]);

        $this->makeService()->search('user1', 'term', null, null, 'oldest');
    }

    // -------------------------------------------------------------------------
    // Additional: term is trimmed before the blank-check
    // -------------------------------------------------------------------------

    public function testSearchTermIsTrimmedBeforeMapperCall(): void {
        // Leading/trailing spaces stripped; trimmed term 'hello' must reach mapper.
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'hello', 50, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', '  hello  ', null, null, 'newest');
    }

    // -------------------------------------------------------------------------
    // Additional: empty result set from mapper returns [] without exception
    // -------------------------------------------------------------------------

    public function testSearchEmptyResultFromMapperReturnsEmpty(): void {
        $this->mapper->method('searchAccessiblePage')->willReturn([]);

        $result = $this->makeService()->search('user1', 'nomatch');
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Additional: exactly limit=200 passes through unclamped
    // -------------------------------------------------------------------------

    public function testSearchLimit200PassesThroughUnclamped(): void {
        $this->mapper->expects($this->once())
            ->method('searchAccessiblePage')
            ->with('user1', [], 'hello', 200, 0, 'newest')
            ->willReturn([]);

        $this->makeService()->search('user1', 'hello', 200, 0, 'newest');
    }
}
