<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Db;

use OCA\CrmNotes\Db\NoteType;
use OCA\CrmNotes\Db\NoteTypeMapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class NoteTypeMapperTest extends TestCase {

    private NoteTypeMapper $mapper;
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
        $this->qb->method('createNamedParameter')->willReturn('param');
        $this->expr->method('eq')->willReturn('eq_expr');
        $this->expr->method('orX')->willReturn('or_expr');
        $this->expr->method('andX')->willReturn('and_expr');

        $this->mapper = new NoteTypeMapper($this->db);
    }

    public function testTableName(): void {
        $this->assertSame('crm_note_types', $this->mapper->getTableName());
    }

    public function testFindAllBuildsCorrectQuery(): void {
        $this->qb->expects($this->once())->method('select')->with('*')->willReturnSelf();
        $this->qb->expects($this->once())->method('from')->with('crm_note_types')->willReturnSelf();
        $this->qb->expects($this->once())->method('where')->willReturnSelf();
        $this->qb->expects($this->once())->method('orderBy')->with('name', 'ASC')->willReturnSelf();

        $this->mapper->findAll('testuser');
    }

    public function testFindByIdBuildsCorrectQuery(): void {
        $this->qb->expects($this->once())->method('select')->with('*')->willReturnSelf();
        $this->qb->expects($this->once())->method('from')->with('crm_note_types')->willReturnSelf();
        $this->qb->expects($this->once())->method('where')->willReturnSelf();
        $this->qb->expects($this->once())->method('andWhere')->willReturnSelf();

        // The stub will return an empty NoteType, which will cause DoesNotExistException
        // from the parent findEntity. But we're testing query building, not result.
        try {
            $this->mapper->findById(1, 'testuser');
        } catch (\Exception $e) {
            // Expected: the stub's findEntity returns an empty entity
        }
    }

    public function testFindAllIncludesGlobalDefaults(): void {
        // Read scope = caller's own rows OR the shared global set
        // (empty user_id AND is_default = true): exactly one orX wrapping an
        // andX, with eq() for the caller's user_id plus eq() for '' and
        // is_default. Globals are read-shared but stay immutable (mutations use
        // the owner-only findOwnedById).
        $this->expr->expects($this->once())->method('orX');
        $this->expr->expects($this->once())->method('andX');
        $this->expr->expects($this->exactly(3))->method('eq');

        $this->mapper->findAll('user1');
    }

    public function testFindByIdIncludesGlobalDefaults(): void {
        // id eq + read scope (orX of own user_id and the '' + is_default global).
        $this->expr->expects($this->once())->method('orX');
        $this->expr->expects($this->once())->method('andX');
        $this->expr->expects($this->exactly(4))->method('eq');

        try {
            $this->mapper->findById(5, 'user1');
        } catch (\Exception $e) {
            // Expected: the stub's findEntity returns an empty entity
        }
    }

    public function testFindOwnedByIdIsOwnerOnly(): void {
        // Mutations must NOT see globals: owner-only, two eq() (id + user_id),
        // no orX().
        $this->expr->expects($this->exactly(2))->method('eq');
        $this->expr->expects($this->never())->method('orX');

        try {
            $this->mapper->findOwnedById(5, 'user1');
        } catch (\Exception $e) {
            // Expected: the stub's findEntity returns an empty entity
        }
    }

    public function testFindGlobalDefaultsMatchesSentinel(): void {
        // Global set lookup: empty user_id AND is_default, two eq(), no orX().
        $this->expr->expects($this->exactly(2))->method('eq');
        $this->expr->expects($this->never())->method('orX');

        $this->mapper->findGlobalDefaults();
    }
}
