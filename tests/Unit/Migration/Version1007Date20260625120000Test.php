<?php

// SPDX-FileCopyrightText: 2026 CRM Notes Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace OCA\CrmNotes\Tests\Unit\Migration;

use OCA\CrmNotes\Migration\Version1007Date20260625120000;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

class Version1007Date20260625120000Test extends TestCase {

    private function makeMigration(?IDBConnection $db = null): Version1007Date20260625120000 {
        return new Version1007Date20260625120000($db ?? $this->createMock(IDBConnection::class));
    }

    public function testShrinksFilePathAndRecreatesUniqueIndex(): void {
        $calls = [];

        $table = new class($calls) {
            public array $calls;
            private array $indexes = ['crm_nf_path_unique' => true];
            public function __construct(array &$calls) {
                $this->calls = &$calls;
            }
            public function hasIndex(string $name): bool {
                return !empty($this->indexes[$name]);
            }
            public function dropIndex(string $name): void {
                $this->calls[] = ['dropIndex', $name];
                $this->indexes[$name] = false;
            }
            public function hasColumn(string $name): bool {
                return true;
            }
            public function changeColumn(string $name, array $options): void {
                $this->calls[] = ['changeColumn', $name, $options];
            }
            public function addUniqueIndex(array $columns, string $name): void {
                $this->calls[] = ['addUniqueIndex', $columns, $name];
                $this->indexes[$name] = true;
            }
        };

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('crm_note_files')->willReturn(true);
        $schema->method('getTable')->with('crm_note_files')->willReturn($table);

        $migration = $this->makeMigration();
        $result = $migration->changeSchema(
            $this->createMock(IOutput::class),
            fn () => $schema,
            [],
        );

        $this->assertSame($schema, $result);

        // The oversized unique index is dropped first.
        $this->assertSame(['dropIndex', 'crm_nf_path_unique'], $calls[0]);

        // file_path is shrunk to an index-safe length.
        $changeColumn = array_values(array_filter($calls, fn ($c) => $c[0] === 'changeColumn'))[0];
        $this->assertSame('file_path', $changeColumn[1]);
        $this->assertSame(1024, $changeColumn[2]['length']);

        // The unique index is recreated on (note_id, file_path).
        $addIndex = array_values(array_filter($calls, fn ($c) => $c[0] === 'addUniqueIndex'))[0];
        $this->assertSame(['note_id', 'file_path'], $addIndex[1]);
        $this->assertSame('crm_nf_path_unique', $addIndex[2]);
    }

    public function testNoOpWhenTableMissing(): void {
        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(false);
        $schema->expects($this->never())->method('getTable');

        $migration = $this->makeMigration();
        $result = $migration->changeSchema(
            $this->createMock(IOutput::class),
            fn () => $schema,
            [],
        );

        $this->assertNull($result);
    }

    public function testPreSchemaChangeDeletesOverLengthRows(): void {
        // The pre-step must DELETE rows whose file_path exceeds the new length so
        // the subsequent column shrink cannot abort the upgrade on a STRICT-mode
        // MySQL/MariaDB with a data-truncation error.
        $expr = $this->createMock(IExpressionBuilder::class);
        $expr->expects($this->once())->method('gt')->willReturn('gt_expr');

        $func = $this->createMock(IFunctionBuilder::class);
        $func->expects($this->once())->method('charLength')->with('file_path')->willReturn('charlen');

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->expects($this->once())->method('delete')->with('crm_note_files')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('func')->willReturn($func);
        $qb->method('createNamedParameter')->willReturn('param');
        $qb->expects($this->once())->method('executeStatement')->willReturn(3);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturn($qb);

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->with('crm_note_files')->willReturn(true);

        $output = $this->createMock(IOutput::class);
        // Some rows removed → a warning is emitted.
        $output->expects($this->atLeastOnce())->method('warning');

        $migration = $this->makeMigration($db);
        $migration->preSchemaChange($output, fn () => $schema, []);
    }

    public function testPreSchemaChangeNoOpWhenTableMissing(): void {
        $db = $this->createMock(IDBConnection::class);
        $db->expects($this->never())->method('getQueryBuilder');

        $schema = $this->createMock(ISchemaWrapper::class);
        $schema->method('hasTable')->willReturn(false);

        $migration = $this->makeMigration($db);
        $migration->preSchemaChange($this->createMock(IOutput::class), fn () => $schema, []);
    }
}
