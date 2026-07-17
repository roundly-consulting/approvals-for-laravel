<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * The seven outbound morph columns follow `approvals.key_type` (default `bigint`) through
 * the toolkit's `morphKey` macro. Two things must hold and are proven here:
 *
 *  - the default (`bigint`) emitted schema is BYTE-IDENTICAL to the pre-macro `morphs()`
 *    output — `morphKey($n, BigInt)` *is* `morphs($n)` — so a default host sees zero change;
 *  - a `uuid` / `ulid` host actually gets uuid / char morph id columns, checked on the only
 *    engine (Postgres) whose catalog can tell the three key types apart. SQLite stores all
 *    three as the same affinity, so the check is meaningless there.
 */
function runApprovalsMigrations(): void
{
    foreach ([
        '0001_create_approvals_table',
        '0002_create_approval_requests_table',
        '0003_add_v11_columns_to_approvals_table',
        '0004_add_staging_to_approval_requests_table',
        '0005_create_approval_request_stages_table',
        '0006_create_approval_delegations_table',
    ] as $migration) {
        (require __DIR__.'/../../database/migrations/'.$migration.'.php')->up();
    }
}

/**
 * Drop only this package's tables (never the migrations bookkeeping or host fixture
 * tables) so a re-migration under a different key type does not break the harness reset.
 */
function dropApprovalsTables(): void
{
    foreach (['approval_request_stages', 'approval_delegations', 'approval_requests', 'approvals'] as $table) {
        Schema::dropIfExists($table);
    }
}

/**
 * The emitted `CREATE TABLE` statement from the sqlite catalog — a key-type regression
 * cannot hide behind a column-existence assertion.
 */
function emittedApprovalsTable(string $table): string
{
    /** @var list<object{sql: string|null}> $rows */
    $rows = DB::select('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);

    return (string) ($rows[0]->sql ?? '');
}

/**
 * The Postgres catalog's answer for a column: the real type, and its length where it has
 * one. This is what makes the key-type cases able to fail at all.
 */
function pgsqlApprovalsColumnType(string $table, string $column): string
{
    /** @var list<object{data_type: string, character_maximum_length: int|null}> $rows */
    $rows = DB::select(
        'select data_type, character_maximum_length from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    $row = $rows[0] ?? null;

    if ($row === null) {
        return 'MISSING';
    }

    return $row->character_maximum_length === null
        ? $row->data_type
        : $row->data_type.'('.$row->character_maximum_length.')';
}

$sqliteOnly = fn (): bool => DriverMatrix::driver() !== 'sqlite';
$pgsqlOnly = fn (): bool => DriverMatrix::driver() !== 'pgsql';

it('emits the frozen bigint morph schema byte-for-byte', function (): void {
    // The harness has already migrated on the default (bigint) config. This is the shipped
    // schema — the sweep's core safety property is that it must never drift.
    expect(emittedApprovalsTable('approvals'))->toBe(
        'CREATE TABLE "approvals" ("id" integer primary key autoincrement not null, '
        .'"actor_type" varchar not null, "actor_id" integer not null, '
        .'"approvable_type" varchar not null, "approvable_id" integer not null, '
        .'"status" varchar not null default \'approved\', "reason" text, '
        .'"approval_request_type" varchar, "approval_request_id" integer, '
        .'"decided_at" datetime, "expires_at" datetime, '
        .'"created_at" datetime, "updated_at" datetime, "deleted_at" datetime, '
        .'"decided_by_type" varchar, "decided_by_id" integer, '
        .'"weight" integer not null default \'1\', "approval_request_stage_id" integer)'
    );

    expect(emittedApprovalsTable('approval_requests'))->toBe(
        'CREATE TABLE "approval_requests" ("id" integer primary key autoincrement not null, '
        .'"subject_type" varchar, "subject_id" integer, "rule" varchar not null, '
        .'"quorum" integer, "required_approvers" integer, '
        .'"status" varchar not null default \'pending\', "resolved_at" datetime, '
        .'"expires_at" datetime, "created_at" datetime, "updated_at" datetime, '
        .'"deleted_at" datetime, "staged" tinyint(1) not null default \'0\', '
        .'"reject_on_stage_rejection" tinyint(1) not null default \'1\', "workflow" varchar)'
    );

    expect(emittedApprovalsTable('approval_delegations'))->toBe(
        'CREATE TABLE "approval_delegations" ("id" integer primary key autoincrement not null, '
        .'"delegator_type" varchar not null, "delegator_id" integer not null, '
        .'"delegate_type" varchar not null, "delegate_id" integer not null, '
        .'"starts_at" datetime, "ends_at" datetime, "revoked_at" datetime, '
        .'"created_at" datetime, "updated_at" datetime, "deleted_at" datetime)'
    );
})->skip($sqliteOnly, 'sqlite_master is the sqlite catalog');

it('renders each configured key type as a distinct real morph column type', function (string $keyType, string $expected): void {
    config()->set('approvals.key_type', $keyType);

    dropApprovalsTables();
    runApprovalsMigrations();

    // Every polymorphic id column follows the configured type, across all three tables.
    expect(pgsqlApprovalsColumnType('approvals', 'actor_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approvals', 'approvable_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approvals', 'approval_request_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approvals', 'decided_by_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approval_requests', 'subject_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approval_delegations', 'delegator_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approval_delegations', 'delegate_id'))->toBe($expected)
        // The morph *type* column names a class — a string on every key type.
        ->and(pgsqlApprovalsColumnType('approvals', 'actor_type'))->toBe('character varying(255)');
})->with([
    'bigint' => ['bigint', 'bigint'],
    'uuid' => ['uuid', 'uuid'],
    'ulid' => ['ulid', 'character(26)'],
])->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart');

it('falls back to the bigint morph schema for an unrecognized key type', function (): void {
    config()->set('approvals.key_type', 'nonsense');

    dropApprovalsTables();
    runApprovalsMigrations();

    // A typo in a host's config must never leave the package unable to migrate.
    expect(Schema::hasColumn('approvals', 'actor_id'))->toBeTrue()
        ->and(DriverMatrix::driver() === 'pgsql' ? pgsqlApprovalsColumnType('approvals', 'actor_id') : 'bigint')
        ->toBe('bigint');
});
