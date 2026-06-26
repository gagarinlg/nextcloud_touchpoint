<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Db;

use OCA\CrmNotes\Db\NoteSharingMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Covers the read-vs-write share distinction (C1): findWritableNoteIds must
 * narrow the query with the can_edit predicate, while findAccessibleNoteIds
 * must not.
 */
class NoteSharingMapperTest extends TestCase {

    private NoteSharingMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('selectDistinct')->willReturnSelf();
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('createNamedParameter')->willReturn('param');

        // orX returns a stub predicate object that records add() calls.
        $orX = new class {
            public function add($x): void {
            }
        };
        $this->expr->method('orX')->willReturn($orX);
        $this->expr->method('andX')->willReturn('andX_expr');
        $this->expr->method('eq')->willReturn('eq_expr');
        $this->expr->method('in')->willReturn('in_expr');

        $this->mapper = new NoteSharingMapper($this->db);
    }

    private function mockResult(array $rows): void {
        $result = new class($rows) {
            private array $rows;
            private int $i = 0;

            public function __construct(array $rows) {
                $this->rows = $rows;
            }

            public function fetch() {
                if ($this->i >= count($this->rows)) {
                    return false;
                }
                return $this->rows[$this->i++];
            }

            public function closeCursor(): void {
            }
        };
        $this->qb->method('executeQuery')->willReturn($result);
    }

    public function testTableName(): void {
        $this->assertSame('crm_note_sharing', $this->mapper->getTableName());
    }

    public function testFindWritableNoteIdsAddsCanEditPredicate(): void {
        $this->mockResult([['note_id' => 7], ['note_id' => 9]]);

        // The write-only branch must add exactly one andWhere (the can_edit
        // filter). The accessible branch never does — see the next test.
        $this->qb->expects($this->once())
            ->method('andWhere')
            ->willReturnSelf();

        $ids = $this->mapper->findWritableNoteIds('user1', []);
        $this->assertSame([7, 9], $ids);
    }

    public function testFindAccessibleNoteIdsDoesNotFilterOnCanEdit(): void {
        $this->mockResult([['note_id' => 3]]);

        // Read access does not constrain on can_edit, so no andWhere is added.
        $this->qb->expects($this->never())->method('andWhere');

        $ids = $this->mapper->findAccessibleNoteIds('user1', []);
        $this->assertSame([3], $ids);
    }

    public function testFindWritableNoteIdsIncludesGroupBranch(): void {
        $this->mockResult([]);

        // With group ids present, the orX predicate gains the group branch and
        // the can_edit andWhere is still applied.
        $this->qb->expects($this->once())->method('andWhere')->willReturnSelf();

        $ids = $this->mapper->findWritableNoteIds('user1', ['staff', 'sales']);
        $this->assertSame([], $ids);
    }

    public function testFindByNoteIdsEmptyReturnsEarly(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->assertSame([], $this->mapper->findByNoteIds([]));
    }

    /**
     * findByNoteIds() must batch a large note-id list into chunks of at most 900
     * (matching NoteMapper) so the IN(...) clause never overflows a strict DB
     * backend's per-query list limit. Each chunk builds its own query, so 2000
     * ids => 3 queries (900 + 900 + 200).
     */
    public function testFindByNoteIdsChunksLargeIdList(): void {
        $emptyResult = new class {
            public function fetch() {
                return false;
            }

            public function closeCursor(): void {
            }
        };

        $qbCount = 0;
        $this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$qbCount, $emptyResult) {
            $qbCount++;
            $qb = $this->createMock(IQueryBuilder::class);
            $qb->method('expr')->willReturn($this->expr);
            $qb->method('select')->willReturnSelf();
            $qb->method('from')->willReturnSelf();
            $qb->method('where')->willReturnSelf();
            $qb->method('createNamedParameter')->willReturn('param');
            $qb->method('executeQuery')->willReturn($emptyResult);
            return $qb;
        });

        $map = $this->mapper->findByNoteIds(range(1, 2000));

        $this->assertSame(3, $qbCount, 'Expected 2000 note ids to be split into 3 chunked queries');
        $this->assertSame([], $map);
    }
}
