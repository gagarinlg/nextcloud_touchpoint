<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Unit\Db;

use OCA\Touchpoint\Db\NoteMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NoteMapper::searchAccessiblePage().
 *
 * These tests use mock objects to verify query-builder interactions (which
 * expressions are built, which parameters are bound). They do NOT verify that
 * the resulting SQL is correctly scoped — that predicate-isolation guarantee
 * is provided by the integration test in tests/Integration/Db/NoteMapperSearchTest.php.
 */
class NoteMapperSearchTest extends TestCase {

    private NoteMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;

    /** Tracks every orX() return value in call order, reset per test via setUp. */
    private array $orXInstances = [];

    protected function setUp(): void {
        $this->db   = $this->createMock(IDBConnection::class);
        $this->qb   = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->orXInstances = [];

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
        $this->qb->method('createNamedParameter')->willReturn(':param');
        $this->expr->method('eq')->willReturn('eq_expr');
        $this->expr->method('in')->willReturn('in_expr');
        $this->expr->method('iLike')->willReturn('ilike_expr');

        // orX stub creates a trackable sink object on each call. The created
        // objects are captured into $this->orXInstances so tests can assert on
        // add() call counts per orX instance (visibility vs. search predicate).
        $instances = &$this->orXInstances;
        $this->expr->method('orX')->willReturnCallback(function (...$args) use (&$instances) {
            $obj = new class {
                public int $added = 0;
                public function add($expr): void {
                    $this->added++;
                }
            };
            $instances[] = $obj;
            return $obj;
        });

        $this->mapper = new NoteMapper($this->db);
    }

    // -------------------------------------------------------------------------
    // (a) iLike called on both 'title' and 'content' in searchAccessiblePage
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageCallsILikeOnTitleAndContent(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $ilikeCalls = [];
        $this->expr->method('iLike')->willReturnCallback(function ($col, $param) use (&$ilikeCalls) {
            $ilikeCalls[] = $col;
            return 'ilike_expr';
        });

        $this->mapper->searchAccessiblePage('userA', [], 'foo', null, null);

        $this->assertContains('title',   $ilikeCalls, 'iLike must be called on the title column');
        $this->assertContains('content', $ilikeCalls, 'iLike must be called on the content column');
        $this->assertCount(2, $ilikeCalls, 'iLike must be called exactly twice (once per column)');
    }

    // -------------------------------------------------------------------------
    // (b) escapeLikeParameter invoked via buildLikePattern in searchAccessiblePage
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageCallsEscapeLikeParameter(): void {
        $this->db->expects($this->atLeastOnce())
            ->method('escapeLikeParameter')
            ->with('hello')
            ->willReturn('hello');

        $this->mapper->searchAccessiblePage('userA', [], 'hello', null, null);
    }

    // -------------------------------------------------------------------------
    // (c) createNamedParameter wraps the pattern at the iLike call site
    //     iLike must never receive a raw PHP string as its $y argument.
    //     We verify this by checking that the $y arg passed to iLike() equals
    //     the return value of createNamedParameter() (i.e. ':param').
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageILikeReceivesNamedParameter(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        // createNamedParameter returns ':param'; iLike $y must equal that value.
        $this->qb->method('createNamedParameter')->willReturn(':param');

        $iLikeYArgs = [];
        $this->expr->method('iLike')->willReturnCallback(function ($col, $y) use (&$iLikeYArgs) {
            $iLikeYArgs[] = $y;
            return 'ilike_expr';
        });

        $this->mapper->searchAccessiblePage('userA', [], 'foo', null, null);

        foreach ($iLikeYArgs as $y) {
            $this->assertSame(':param', $y,
                'iLike $y must be the named parameter placeholder, not a raw PHP string');
        }
    }

    // -------------------------------------------------------------------------
    // (d) WHERE is structured as two separate andWhere() calls (visibility orX
    //     passed to where(), search orX passed to andWhere())
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageUsesWhereAndAndWhere(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $this->qb->expects($this->atLeastOnce())->method('where')->willReturnSelf();
        $this->qb->expects($this->atLeastOnce())->method('andWhere')->willReturnSelf();

        $this->mapper->searchAccessiblePage('userA', [], 'foo', null, null);
    }

    // -------------------------------------------------------------------------
    // (e) Non-empty sharedIds: visibility orX contains eq('user_id') AND at
    //     least one in('id') clause
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageNonEmptySharedIdsAddsInClause(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $this->mapper->searchAccessiblePage('userA', [1, 2, 3], 'foo', null, null);

        // $this->orXInstances[0] is the visibility predicate (first orX created).
        $this->assertNotEmpty($this->orXInstances, 'orX must be called at least once');
        $visibilityOrX = $this->orXInstances[0];
        // add() must have been called once (one chunk of [1, 2, 3])
        $this->assertSame(1, $visibilityOrX->added,
            'Visibility orX must have one in() branch added for the non-empty shared-id list');
    }

    // -------------------------------------------------------------------------
    // (f) Empty sharedIds: visibility predicate is only eq('user_id'), no in()
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageEmptySharedIdsNoInClause(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $this->mapper->searchAccessiblePage('userA', [], 'foo', null, null);

        $this->assertNotEmpty($this->orXInstances);
        $visibilityOrX = $this->orXInstances[0];
        $this->assertSame(0, $visibilityOrX->added,
            'With no shared ids, no in() branch should be added to the visibility orX');
    }

    // -------------------------------------------------------------------------
    // (g) limit, offset, orderBy wired correctly
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageLimitAndOffsetWired(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $this->qb->expects($this->once())->method('setMaxResults')->with(25)->willReturnSelf();
        $this->qb->expects($this->once())->method('setFirstResult')->with(50)->willReturnSelf();
        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'DESC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'DESC')->willReturnSelf();

        $this->mapper->searchAccessiblePage('userA', [], 'foo', 25, 50);
    }

    public function testSearchAccessiblePageOldestSortAscends(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $this->qb->expects($this->once())->method('orderBy')->with('created_at', 'ASC')->willReturnSelf();
        $this->qb->expects($this->once())->method('addOrderBy')->with('id', 'ASC')->willReturnSelf();

        $this->mapper->searchAccessiblePage('userA', [], 'foo', null, null, NoteMapper::SORT_OLDEST);
    }

    public function testSearchAccessiblePageNullLimitAndOffsetNotSet(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        $this->qb->expects($this->never())->method('setMaxResults');
        $this->qb->expects($this->never())->method('setFirstResult');

        $this->mapper->searchAccessiblePage('userA', [], 'foo', null, null);
    }

    // -------------------------------------------------------------------------
    // (i) Empty $term builds a valid query without throwing
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageEmptyTermDoesNotThrow(): void {
        $this->db->method('escapeLikeParameter')->with('')->willReturn('');

        // Should not throw; NoteService intercepts blank terms before the mapper
        // is called, but the mapper itself must handle empty string gracefully.
        $this->mapper->searchAccessiblePage('userA', [], '', null, null);
        $this->addToAssertionCount(1); // reached without exception
    }

    // -------------------------------------------------------------------------
    // Additional: large sharedIds list is chunked (mirrors findAccessiblePage)
    // -------------------------------------------------------------------------

    public function testSearchAccessiblePageChunksLargeSharedIdSet(): void {
        $this->db->method('escapeLikeParameter')->willReturn('foo');

        // 2000 ids => 3 chunks (900 + 900 + 200)
        $this->mapper->searchAccessiblePage('userA', range(1, 2000), 'foo', null, null);

        $this->assertNotEmpty($this->orXInstances);
        $visibilityOrX = $this->orXInstances[0];
        $this->assertSame(3, $visibilityOrX->added,
            'Expected 2000 shared ids to be chunked into 3 IN branches');
    }
}
