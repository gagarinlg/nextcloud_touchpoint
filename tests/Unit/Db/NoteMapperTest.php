<?php

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Db;

use OCA\CrmNotes\Db\Note;
use OCA\CrmNotes\Db\NoteMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class NoteMapperTest extends TestCase {

    private NoteMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('select')->willReturnSelf();
        $this->qb->method('from')->willReturnSelf();
        $this->qb->method('where')->willReturnSelf();
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('addOrderBy')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();
        $this->qb->method('setFirstResult')->willReturnSelf();
        $this->qb->method('createNamedParameter')->willReturn('param');
        $this->expr->method('eq')->willReturn('eq_expr');

        $this->mapper = new NoteMapper($this->db);
    }

    public function testTableName(): void {
        $this->assertSame('crm_notes', $this->mapper->getTableName());
    }

    public function testFindAllBuildsQuery(): void {
        $this->qb->expects($this->once())->method('select')->with('*')->willReturnSelf();
        $this->qb->expects($this->once())->method('from')->with('crm_notes')->willReturnSelf();
        // Default sort: created_at is the primary key, descending (newest first),
        // followed by the stable id tiebreaker so LIMIT/OFFSET paging is
        // deterministic.
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'DESC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'DESC')->willReturnSelf();

        $this->mapper->findAll('user1');
    }

    public function testFindAllOldestSortAscends(): void {
        // 'oldest' flips both the primary created_at key and the id tiebreaker
        // to ascending.
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'ASC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'ASC')->willReturnSelf();

        $this->mapper->findAll('user1', null, null, NoteMapper::SORT_OLDEST);
    }

    public function testFindAllPublicHasStableIdTiebreaker(): void {
        // Default newest-first: created_at DESC primary, id DESC tiebreaker.
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'DESC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'DESC')->willReturnSelf();

        $this->mapper->findAllPublic();
    }

    public function testFindAllPublicOldestSortAscends(): void {
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'ASC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'ASC')->willReturnSelf();

        $this->mapper->findAllPublic(null, null, NoteMapper::SORT_OLDEST);
    }

    public function testFindAllPublicWithLimitAndOffset(): void {
        $this->qb->expects($this->once())->method('setMaxResults')->with(7)->willReturnSelf();
        $this->qb->expects($this->once())->method('setFirstResult')->with(14)->willReturnSelf();
        $this->mapper->findAllPublic(7, 14);
    }

    public function testFindAllWithLimit(): void {
        $this->qb->expects($this->once())->method('setMaxResults')->with(10)->willReturnSelf();
        $this->mapper->findAll('user1', 10);
    }

    public function testFindAllWithOffset(): void {
        $this->qb->expects($this->once())->method('setFirstResult')->with(20)->willReturnSelf();
        $this->mapper->findAll('user1', null, 20);
    }

    public function testFindAllWithLimitAndOffset(): void {
        $this->qb->expects($this->once())->method('setMaxResults')->with(5)->willReturnSelf();
        $this->qb->expects($this->once())->method('setFirstResult')->with(10)->willReturnSelf();
        $this->mapper->findAll('user1', 5, 10);
    }

    public function testFindAllWithoutLimitAndOffset(): void {
        $this->qb->expects($this->never())->method('setMaxResults');
        $this->qb->expects($this->never())->method('setFirstResult');
        $this->mapper->findAll('user1');
    }

    public function testFindByIdBuildsQuery(): void {
        $this->qb->expects($this->once())->method('select')->with('*')->willReturnSelf();
        $this->qb->expects($this->once())->method('where')->willReturnSelf();
        $this->qb->expects($this->once())->method('andWhere')->willReturnSelf();

        try {
            $this->mapper->findById(1, 'user1');
        } catch (\Exception $e) {
            // Expected
        }
    }

    public function testFindByContactBuildsQuery(): void {
        $this->qb->expects($this->once())->method('select')->with('*')->willReturnSelf();
        $this->qb->expects($this->once())->method('where')->willReturnSelf();
        $this->qb->expects($this->once())->method('andWhere')->willReturnSelf();
        // Pinned notes float to the top; within each group the order is
        // created_at (default DESC) then the id tiebreaker in the same direction.
        $this->qb->expects($this->once())->method('orderBy')->with('is_pinned', 'DESC')->willReturnSelf();
        $addOrderByArgs = [];
        $this->qb->expects($this->exactly(2))->method('addOrderBy')
            ->willReturnCallback(function (string $col, string $dir) use (&$addOrderByArgs) {
                $addOrderByArgs[] = [$col, $dir];
                return $this->qb;
            });

        $this->mapper->findByContact('uid-123', 'user1');

        $this->assertSame([['created_at', 'DESC'], ['id', 'DESC']], $addOrderByArgs);
    }

    public function testFindByContactOldestSortAscends(): void {
        $this->qb->expects($this->once())->method('orderBy')->with('is_pinned', 'DESC')->willReturnSelf();
        $addOrderByArgs = [];
        $this->qb->expects($this->exactly(2))->method('addOrderBy')
            ->willReturnCallback(function (string $col, string $dir) use (&$addOrderByArgs) {
                $addOrderByArgs[] = [$col, $dir];
                return $this->qb;
            });

        $this->mapper->findByContact('uid-123', 'user1', NoteMapper::SORT_OLDEST);

        // Pinned-first is preserved; created_at + id tiebreaker flip to ASC.
        $this->assertSame([['created_at', 'ASC'], ['id', 'ASC']], $addOrderByArgs);
    }

    public function testFindByContactUsesCorrectParams(): void {
        // 2 eq calls: contact_uid and user_id
        $this->expr->expects($this->exactly(2))->method('eq');
        $this->mapper->findByContact('uid-abc', 'user1');
    }

    public function testFindByIdUsesCorrectParams(): void {
        // 2 eq calls: id and user_id
        $this->expr->expects($this->exactly(2))->method('eq');
        try {
            $this->mapper->findById(10, 'user1');
        } catch (\Exception $e) {
            // Expected
        }
    }

    /**
     * findByIds() must batch a large id list into chunks of at most 900 so the
     * IN(...) clause never overflows a DB backend's per-query list limit. Each
     * chunk builds its own query (one getQueryBuilder() + one executeQuery()),
     * so 2000 ids => 3 queries (900 + 900 + 200).
     */
    public function testFindByIdsChunksLargeIdList(): void {
        $this->expr->method('in')->willReturn('in_expr');

        $result = $this->createMock(IResult::class);
        $result->method('fetchAll')->willReturn([]);
        $result->method('fetch')->willReturn(false);

        $qbCount = 0;
        $this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$qbCount, $result) {
            $qbCount++;
            $qb = $this->createMock(IQueryBuilder::class);
            $qb->method('expr')->willReturn($this->expr);
            $qb->method('select')->willReturnSelf();
            $qb->method('from')->willReturnSelf();
            $qb->method('where')->willReturnSelf();
            $qb->method('andWhere')->willReturnSelf();
            $qb->method('createNamedParameter')->willReturn('param');
            $qb->method('executeQuery')->willReturn($result);
            return $qb;
        });

        $ids = range(1, 2000);
        $this->mapper->findByIds($ids, null);

        $this->assertSame(3, $qbCount, 'Expected 2000 ids to be split into 3 chunked queries');
    }

    /**
     * findSortKeysByIds() must likewise chunk a large id list and merge the
     * resulting maps. 2000 ids => 3 chunked queries.
     */
    public function testFindSortKeysByIdsChunksLargeIdList(): void {
        $this->expr->method('in')->willReturn('in_expr');

        // getQueryBuilder is already stubbed in setUp() to return $this->qb, so
        // re-stubbing it here would be shadowed (PHPUnit's first rule wins).
        // Instead assert on $this->qb directly: 2000 ids => 3 chunks (900 + 900
        // + 200) => exactly 3 executeQuery() calls.
        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(false);
        $this->qb->expects($this->exactly(3))
            ->method('executeQuery')
            ->willReturn($result);

        $ids = range(1, 2000);
        $map = $this->mapper->findSortKeysByIds($ids, 'user1');

        $this->assertSame([], $map);
    }

    /**
     * findSortKeysByIds() must surface the is_pinned flag (cast to bool) so
     * contact-scoped callers can apply their pinned-first ordering on the id
     * window without loading full rows.
     */
    public function testFindSortKeysByIdsReturnsPinnedFlag(): void {
        $this->expr->method('in')->willReturn('in_expr');

        $result = $this->createMock(IResult::class);
        $rows = [
            ['id' => 1, 'updated_at' => '2026-06-01 00:00:00', 'created_at' => '2026-05-01 00:00:00', 'is_pinned' => 1],
            ['id' => 2, 'updated_at' => null, 'created_at' => '2026-05-02 00:00:00', 'is_pinned' => 0],
            false,
        ];
        $i = 0;
        $result->method('fetch')->willReturnCallback(function () use (&$i, $rows) {
            return $rows[$i++];
        });
        $this->qb->method('executeQuery')->willReturn($result);

        $map = $this->mapper->findSortKeysByIds([1, 2], 'user1');

        $this->assertTrue($map[1]['is_pinned']);
        $this->assertFalse($map[2]['is_pinned']);
        $this->assertSame('2026-06-01 00:00:00', $map[1]['updated_at']);
        $this->assertNull($map[2]['updated_at']);
    }

    /**
     * findAccessiblePage() pushes the ordering + window into SQL: it must select
     * the full rows, order by updated_at/created_at/id DESC, and apply
     * setMaxResults/setFirstResult for the requested page — never load the whole
     * key set.
     */
    public function testFindAccessiblePageBuildsOrderedWindowedQuery(): void {
        $orX = new class {
            public int $added = 0;
            public function add($x): void {
                $this->added++;
            }
        };
        $this->expr->method('orX')->willReturn($orX);
        $this->expr->method('in')->willReturn('in_expr');

        $this->qb->expects($this->once())->method('select')->with('*')->willReturnSelf();
        // created_at is now the primary sort (descending by default), with the id
        // tiebreaker as the single addOrderBy.
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'DESC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'DESC')->willReturnSelf();
        $this->qb->expects($this->once())->method('setMaxResults')->with(25)->willReturnSelf();
        $this->qb->expects($this->once())->method('setFirstResult')->with(50)->willReturnSelf();

        $this->mapper->findAccessiblePage('user1', [2, 5], 25, 50);

        // One owned-eq branch (added at orX construction) plus one IN(...) branch
        // for the single shared-id chunk.
        $this->assertSame(1, $orX->added, 'Shared-id IN(...) branch must be OR-added once');
    }

    /**
     * findAccessiblePage() must split a shared-id set larger than the per-IN cap
     * (900) into chunked OR(IN ...) groups so the single ordered+windowed query
     * is preserved. 2000 shared ids => 3 IN-branch additions.
     */
    public function testFindAccessiblePageOldestSortAscends(): void {
        $orX = new class {
            public int $added = 0;
            public function add($x): void {
                $this->added++;
            }
        };
        $this->expr->method('orX')->willReturn($orX);
        $this->expr->method('in')->willReturn('in_expr');

        // 'oldest' flips both the created_at primary key and the id tiebreaker
        // to ascending.
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'ASC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'ASC')->willReturnSelf();

        $this->mapper->findAccessiblePage('user1', [2, 5], 25, 50, NoteMapper::SORT_OLDEST);
    }

    public function testFindAccessiblePageChunksLargeSharedIdSet(): void {
        $orX = new class {
            public int $added = 0;
            public function add($x): void {
                $this->added++;
            }
        };
        $this->expr->method('orX')->willReturn($orX);
        $this->expr->method('in')->willReturn('in_expr');

        $this->mapper->findAccessiblePage('user1', range(1, 2000), 25, 0);

        $this->assertSame(3, $orX->added, 'Expected 2000 shared ids to add 3 chunked IN branches');
    }

    public function testFindAccessiblePageWithNoSharesAndNoWindow(): void {
        $orX = new class {
            public int $added = 0;
            public function add($x): void {
                $this->added++;
            }
        };
        $this->expr->method('orX')->willReturn($orX);
        $this->expr->method('in')->willReturn('in_expr');

        $this->qb->expects($this->never())->method('setMaxResults');
        $this->qb->expects($this->never())->method('setFirstResult');

        $this->mapper->findAccessiblePage('user1', [], null, null);

        // No shared ids => no IN branch added beyond the owned-eq constructed one.
        $this->assertSame(0, $orX->added);
    }

    public function testFindByIdsEmptyReturnsEarly(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->assertSame([], $this->mapper->findByIds([], null));
    }

    public function testFindSortKeysByIdsEmptyReturnsEarly(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');
        $this->assertSame([], $this->mapper->findSortKeysByIds([], null));
    }
}
