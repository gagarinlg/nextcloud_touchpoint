<?php

// SPDX-FileCopyrightText: 2026 Touchpoint Contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * A minimal but FAITHFUL PDO-backed implementation of the OCP query-builder
 * surface that NoteMapper::searchAccessiblePage()/findAccessiblePage() drive.
 *
 * Purpose
 * -------
 * The integration suite has no real Nextcloud bootstrap, so it cannot use the
 * production OC\DB\QueryBuilder. To exercise the SHIPPED NoteMapper code path
 * end-to-end against a live engine (rather than a hand-rebuilt parallel query),
 * this adapter lets the production mapper build its query exactly as it does in
 * production — the same expr()->orX()/andWhere() composition that encodes the
 * security-critical "(visibility) AND (search)" parenthesisation — and then
 * renders that composition to real SQL and runs it on the integration PDO.
 *
 * Critically, this adapter contains NO knowledge of the search/visibility
 * semantics: it only knows how to render eq/in/iLike/orX/andX/where/andWhere
 * into SQL. The parenthesisation that isolates users is produced by the
 * production mapper, not by this file. A regression that flattened the mapper's
 * andWhere($search) into orWhere($search), or that collapsed the two orX groups,
 * would render to different SQL here and fail the integration assertions — which
 * is the whole point.
 *
 * Engine notes mirror the rest of the suite: SQLite has no native ILIKE, so
 * iLike() is rendered as LOWER(col) LIKE LOWER(?). PostgreSQL/MySQL use the
 * native operator. No ESCAPE clause is emitted (matching the OCP builder), so
 * escapeLikeParameter()'s backslash escaping is honoured only where the engine
 * treats backslash as the default LIKE escape — identical to production.
 */

declare(strict_types=1);

namespace OCA\Touchpoint\Tests\Integration\Db;

use OCA\Touchpoint\Db\NoteMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PDO;

/**
 * Marker for a SQL function call built via IQueryBuilder::func(), e.g.
 * COUNT(*) AS cnt. Rendered inline by select() below.
 */
final class PdoFunctionCall {
    public function __construct(public string $sql) {
    }
}

final class PdoFunctionBuilder implements IFunctionBuilder {
    public function count($count, $alias = ''): PdoFunctionCall {
        $expr = is_array($count) ? implode(', ', $count) : (string) $count;
        $sql = "COUNT({$expr})";
        if ($alias !== '') {
            $sql .= " AS {$alias}";
        }
        return new PdoFunctionCall($sql);
    }

    public function charLength($field, $alias = ''): PdoFunctionCall {
        $sql = "LENGTH({$field})";
        if ($alias !== '') {
            $sql .= " AS {$alias}";
        }
        return new PdoFunctionCall($sql);
    }
}

/**
 * Minimal OCP\DB\IResult implementation backed by an already-fetched row set,
 * so mapper code that calls executeQuery() directly (bypassing
 * QBMapper::findEntities()/findEntity(), e.g. NoteMapper::findSortKeysByIds()/
 * countByNoteType()/findIdsByContact() and NoteSharingMapper::queryNoteIds())
 * can be driven exactly as in production: fetch()-in-a-loop, fetchOne(),
 * fetchAll(), closeCursor().
 */
final class PdoResult implements IResult {
    private int $pos = 0;

    /** @param array<int, array<string, mixed>> $rows */
    public function __construct(private array $rows) {
    }

    public function closeCursor(): bool {
        return true;
    }

    public function fetch(int $fetchMode = 0) {
        if ($this->pos >= count($this->rows)) {
            return false;
        }
        return $this->rows[$this->pos++];
    }

    public function fetchAll(int $fetchMode = 0): array {
        $remaining = array_slice($this->rows, $this->pos);
        $this->pos = count($this->rows);
        return $remaining;
    }

    public function fetchColumn() {
        return $this->fetchOne();
    }

    public function fetchOne() {
        $row = $this->fetch();
        if ($row === false) {
            return false;
        }
        return array_values($row)[0] ?? false;
    }

    public function rowCount(): int {
        return count($this->rows);
    }

    public function columnCount(): int {
        return count($this->rows) > 0 ? count($this->rows[0]) : 0;
    }
}

/**
 * Marker/holder for a composed boolean group (orX/andX). Renders to a
 * parenthesised SQL fragment joined by its boolean operator.
 */
final class PdoPredicate {
    /** @var string[] $fragments rendered SQL fragments */
    public array $fragments;

    public function __construct(public string $glue, array $fragments) {
        $this->fragments = $fragments;
    }

    public function add($fragment): void {
        $this->fragments[] = $fragment instanceof self ? $fragment->render() : (string) $fragment;
    }

    public function render(): string {
        $parts = array_map(
            fn ($f) => $f instanceof self ? $f->render() : (string) $f,
            $this->fragments,
        );
        // Drop empties so an orX() seeded with one branch and never added-to
        // still renders cleanly.
        $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
        if (count($parts) === 0) {
            return '';
        }
        return '(' . implode(' ' . $this->glue . ' ', $parts) . ')';
    }
}

final class PdoExpressionBuilder implements IExpressionBuilder {
    public function __construct(private PdoQueryBuilder $qb, private string $dbType) {
    }

    public function eq($x, $y, $type = null): string {
        return "{$x} = {$y}";
    }

    public function in($x, $y, $type = null): string {
        // $y is a placeholder group string like "(?, ?, ?)" produced by
        // createNamedParameter() for an array value.
        return "{$x} IN {$y}";
    }

    public function like($x, $y, $type = null): string {
        return "{$x} LIKE {$y}";
    }

    public function iLike($x, $y, $type = null): string {
        // Mirror execSearchAccessible(): SQLite has no ILIKE, emulate with LOWER();
        // PostgreSQL has native ILIKE; MySQL LIKE is case-insensitive by collation.
        if ($this->dbType === 'sqlite') {
            return "LOWER({$x}) LIKE LOWER({$y})";
        }
        if ($this->dbType === 'pgsql' || $this->dbType === 'postgresql') {
            return "{$x} ILIKE {$y}";
        }
        return "{$x} LIKE {$y}";
    }

    public function gt($x, $y, $type = null): string {
        return "{$x} > {$y}";
    }

    public function andX(...$args): PdoPredicate {
        return new PdoPredicate('AND', $this->flatten($args));
    }

    public function orX(...$args): PdoPredicate {
        return new PdoPredicate('OR', $this->flatten($args));
    }

    /** @return string[] */
    private function flatten(array $args): array {
        $out = [];
        foreach ($args as $a) {
            $out[] = $a instanceof PdoPredicate ? $a->render() : (string) $a;
        }
        return $out;
    }
}

/**
 * PDO-backed query builder that renders the production mapper's composition to
 * SQL and binds parameters positionally. Only the subset of IQueryBuilder that
 * NoteMapper's read paths use is implemented; unused methods throw so a future
 * mapper change that relies on an un-rendered method fails loudly rather than
 * silently producing wrong SQL.
 */
final class PdoQueryBuilder implements IQueryBuilder {
    private string $select = '*';
    private string $table = '';
    private array $where = [];
    private array $order = [];
    private ?int $maxResults = null;
    private ?int $firstResult = null;

    /** @var array<int, mixed> ordered positional bind values */
    public array $binds = [];

    public function __construct(private PDO $pdo, private string $dbType) {
    }

    public function expr(): PdoExpressionBuilder {
        return new PdoExpressionBuilder($this, $this->dbType);
    }

    public function func(): PdoFunctionBuilder {
        return new PdoFunctionBuilder();
    }

    public function select(...$columns): static {
        $cols = [];
        foreach ($columns as $c) {
            if ($c instanceof PdoFunctionCall) {
                $cols[] = $c->sql;
                continue;
            }
            $cols[] = is_array($c) ? implode(', ', $c) : (string) $c;
        }
        $this->select = implode(', ', $cols) ?: '*';
        return $this;
    }

    public function selectDistinct($select): static {
        $this->select = 'DISTINCT ' . (is_array($select) ? implode(', ', $select) : (string) $select);
        return $this;
    }

    public function from($table, $alias = null): static {
        $this->table = (string) $table;
        return $this;
    }

    public function join($fromAlias, $join, $alias, $condition = null): static {
        throw new \BadMethodCallException('join() not supported in PdoQueryBuilder');
    }

    public function where(...$predicates): static {
        // First clause has no leading boolean operator.
        $this->where = [['', $this->renderPredicate($predicates)]];
        return $this;
    }

    public function andWhere(...$args): static {
        $this->where[] = ['AND', $this->renderPredicate($args)];
        return $this;
    }

    /**
     * Not used by the production read paths, but implemented so that a
     * regression flipping andWhere($search) to orWhere($search) renders the
     * actual leaking SQL (visibility OR search) and is caught by the
     * cross-user-leak / AND-semantics integration tests, rather than masked by a
     * BadMethodCallException.
     */
    public function orWhere(...$args): static {
        $this->where[] = ['OR', $this->renderPredicate($args)];
        return $this;
    }

    private function renderPredicate(array $args): string {
        $rendered = [];
        foreach ($args as $a) {
            $rendered[] = $a instanceof PdoPredicate ? $a->render() : (string) $a;
        }
        $rendered = array_values(array_filter($rendered, static fn ($p) => $p !== ''));
        if (count($rendered) === 1) {
            return $rendered[0];
        }
        return '(' . implode(' AND ', $rendered) . ')';
    }

    public function orderBy($sort, $order = null): static {
        $this->order = [trim($sort . ' ' . ($order ?? 'ASC'))];
        return $this;
    }

    public function addOrderBy($sort, $order = null): static {
        $this->order[] = trim($sort . ' ' . ($order ?? 'ASC'));
        return $this;
    }

    public function setMaxResults(?int $limit): static {
        $this->maxResults = $limit;
        return $this;
    }

    public function setFirstResult(?int $offset): static {
        $this->firstResult = $offset;
        return $this;
    }

    public function createNamedParameter($value, $type = null, ?string $name = null): string {
        if (is_array($value)) {
            $placeholders = [];
            foreach ($value as $v) {
                $this->binds[] = $v;
                $placeholders[] = '?';
            }
            return '(' . implode(', ', $placeholders) . ')';
        }
        $this->binds[] = $value;
        return '?';
    }

    public function delete(?string $table = null, ?string $alias = null): static {
        throw new \BadMethodCallException('delete() not supported in PdoQueryBuilder');
    }

    public function executeStatement(): int {
        throw new \BadMethodCallException('executeStatement() not supported in PdoQueryBuilder');
    }

    /**
     * Assemble the rendered SELECT and run it against the integration PDO.
     * Returns an IResult-compatible wrapper around the fetched rows so both
     * calling conventions used by the production mappers work unmodified:
     * QBMapper::findEntities()/findEntity() (which read $result->fetchAll()/
     * iterate) and mapper methods that call executeQuery() directly and
     * fetch()-loop or fetchOne() (e.g. findSortKeysByIds(), countByNoteType(),
     * findIdsByContact(), NoteSharingMapper::queryNoteIds()).
     */
    public function executeQuery(): PdoResult {
        $stmt = $this->pdo->prepare($this->getSQL());
        $stmt->execute($this->binds);
        return new PdoResult($stmt->fetchAll());
    }

    public function getSQL(): string {
        $sql = "SELECT {$this->select} FROM {$this->table}";
        if ($this->where !== []) {
            $clause = '';
            foreach ($this->where as [$op, $fragment]) {
                $clause .= ($op === '' ? '' : " {$op} ") . $fragment;
            }
            $sql .= ' WHERE ' . $clause;
        }
        if ($this->order !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->order);
        }
        if ($this->maxResults !== null) {
            $sql .= ' LIMIT ' . (int) $this->maxResults;
        }
        if ($this->firstResult !== null) {
            $sql .= ' OFFSET ' . (int) $this->firstResult;
        }
        return $sql;
    }
}

/**
 * IDBConnection adapter handing out PdoQueryBuilder instances and exposing the
 * SAME escapeLikeParameter() escaping the production IDBConnection performs.
 */
final class PdoDbConnection implements IDBConnection {
    public function __construct(private PDO $pdo, private string $dbType) {
    }

    public function getQueryBuilder(): PdoQueryBuilder {
        return new PdoQueryBuilder($this->pdo, $this->dbType);
    }

    public function escapeLikeParameter(string $param): string {
        // Mirror OC\DB\Connection::escapeLikeParameter(): backslash-escape the
        // LIKE metacharacters \, % and _.
        return str_replace(
            ['\\', '_', '%'],
            ['\\\\', '\\_', '\\%'],
            $param,
        );
    }
}

/**
 * NoteMapper subclass for integration: the ONLY override is findEntities(),
 * which the stub QBMapper would otherwise short-circuit to []. This override is
 * generic row hydration (executeQuery() + map columns onto a Note) — it adds NO
 * search/visibility logic. The query whose rows it returns is built entirely by
 * the inherited, SHIPPED searchAccessiblePage()/findAccessiblePage() code.
 */
final class IntegrationNoteMapper extends NoteMapper {
    /**
     * Redirect the inherited read paths to the integration fixture table. Only
     * the table NAME is overridden — searchAccessiblePage()/findAccessiblePage()
     * still compose their WHERE/ORDER exactly as shipped against getTableName().
     */
    public function getTableName(): string {
        return TP_TEST_NOTES_TABLE;
    }

    /**
     * @param PdoQueryBuilder $query
     * @return \OCA\Touchpoint\Db\Note[]
     */
    protected function findEntities($query): array {
        $rows = $query->executeQuery()->fetchAll();
        $entities = [];
        foreach ($rows as $row) {
            // Hydrate only the scalar string columns the assertions read. The
            // production query is SELECT *, but Note's typed properties
            // (isPinned: bool, createdAt: ?DateTime) would TypeError if a raw
            // SQLite int / DB timestamp string were assigned through the stub
            // Entity setter, which performs no type coercion. Mapping just the
            // string columns keeps hydration faithful to what the tests inspect
            // without depending on the production QBMapper's full type coercion.
            $note = new \OCA\Touchpoint\Db\Note();
            $note->setId((int) ($row['id'] ?? 0));
            $note->setTitle((string) ($row['title'] ?? ''));
            $note->setContent($row['content'] ?? null);
            $note->setUserId((string) ($row['user_id'] ?? ''));
            $entities[] = $note;
        }
        return $entities;
    }
}
