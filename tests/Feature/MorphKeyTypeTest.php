<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Tests\Fixtures\StringKeyedReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\Fixtures\StringKeyedReviewerTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
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
 *
 * `approval_request_id` is the exception: it points at the package's own bigint
 * `approval_requests.id`. The shipped create migration still lets it follow the key type,
 * and `0007` turns the one shape that breaks (a Postgres `uuid` column) into a bigint.
 */
function runApprovalsMigrations(bool $upgrade = true): void
{
    $migrations = [
        '0001_create_approvals_table',
        '0002_create_approval_requests_table',
        '0003_add_v11_columns_to_approvals_table',
        '0004_add_staging_to_approval_requests_table',
        '0005_create_approval_request_stages_table',
        '0006_create_approval_delegations_table',
    ];

    if ($upgrade) {
        $migrations[] = '0007_change_approval_request_id_to_bigint';
    }

    foreach ($migrations as $migration) {
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
 * Host tables keyed by the configured key type, for the string-keyed fixture models.
 */
function createStringKeyedFixtureTables(): void
{
    foreach (['string_keyed_releases', 'string_keyed_reviewers'] as $table) {
        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $table): void {
            $table->string('id', 36)->primary();
        });
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
        .'"decision_scope" varchar not null default \'\', "live" tinyint(1), '
        .'"decided_at" datetime, "expires_at" datetime, '
        .'"created_at" datetime, "updated_at" datetime, "deleted_at" datetime, '
        .'"decided_by_type" varchar, "decided_by_id" integer, '
        .'"weight" integer not null default \'1\', "approval_request_stage_id" integer)'
    );

    expect(emittedApprovalsTable('approval_requests'))->toBe(
        'CREATE TABLE "approval_requests" ("id" integer primary key autoincrement not null, '
        .'"subject_type" varchar, "subject_id" integer, "rule" varchar not null, '
        .'"quorum" integer, "required_approvers" integer, "approvers" text, '
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

it('renders each configured key type as a distinct real morph column type', function (string $keyType, string $expected, string $requestId): void {
    config()->set('approvals.key_type', $keyType);

    dropApprovalsTables();
    runApprovalsMigrations();

    // Every polymorphic id column that points at a host model follows the configured
    // type, across all three tables.
    expect(pgsqlApprovalsColumnType('approvals', 'actor_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approvals', 'approvable_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approvals', 'decided_by_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approval_requests', 'subject_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approval_delegations', 'delegator_id'))->toBe($expected)
        ->and(pgsqlApprovalsColumnType('approval_delegations', 'delegate_id'))->toBe($expected)
        // The morph *type* column names a class — a string on every key type.
        ->and(pgsqlApprovalsColumnType('approvals', 'actor_type'))->toBe('character varying(255)')
        // A decision's request is the package's own bigint `approval_requests` row. 0007
        // turns the uuid column 0001 creates into a bigint; a char(26) one already works.
        ->and(pgsqlApprovalsColumnType('approvals', 'approval_request_id'))->toBe($requestId)
        ->and(pgsqlApprovalsColumnType('approvals', 'approval_request_type'))->toBe('character varying(255)')
        ->and(pgsqlApprovalsColumnType('approval_requests', 'id'))->toBe('bigint');
})->with([
    'bigint' => ['bigint', 'bigint', 'bigint'],
    'uuid' => ['uuid', 'uuid', 'bigint'],
    'ulid' => ['ulid', 'character(26)', 'character(26)'],
])->skip($pgsqlOnly, 'needs the postgres catalog to tell the key types apart');

it('pins approval_request_id after the upgrade migration, per key type and driver', function (string $keyType): void {
    config()->set('approvals.key_type', $keyType);

    dropApprovalsTables();
    runApprovalsMigrations();

    $expected = [
        'sqlite' => ['bigint' => 'integer', 'uuid' => 'varchar', 'ulid' => 'varchar'],
        'pgsql' => ['bigint' => 'int8', 'uuid' => 'int8', 'ulid' => 'bpchar'],
    ][DriverMatrix::driver()][$keyType];

    expect(Schema::getColumnType('approvals', 'approval_request_id'))->toBe($expected);
})->with(['bigint', 'uuid', 'ulid'])
    ->skip(fn (): bool => ! in_array(DriverMatrix::driver(), ['sqlite', 'pgsql'], true), 'type names are pinned for the drivers CI runs');

it('records a decision on a request on a string-keyed host', function (string $keyType): void {
    config()->set('approvals.key_type', $keyType);

    dropApprovalsTables();
    runApprovalsMigrations();
    createStringKeyedFixtureTables();

    $release = StringKeyedReleaseTestModel::query()->create();
    $lead = StringKeyedReviewerTestModel::query()->create();

    $request = $release->requestApproval([$lead], ApprovalRule::Unanimous);
    $approval = $lead->approve($release);

    // A Postgres char(26) column (ulid) pads the id with blanks.
    expect(trim((string) $approval->fresh()?->approval_request_id))->toBe((string) $request->getKey())
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved)
        ->and($request->decisions()->count())->toBe(1);
})->with(['uuid', 'ulid']);

it('turns a uuid approval_request_id from 1.0.0 into a bigint', function (): void {
    config()->set('approvals.key_type', 'uuid');

    dropApprovalsTables();
    runApprovalsMigrations(upgrade: false);

    // The column as the create migration leaves it on a uuid host.
    expect(pgsqlApprovalsColumnType('approvals', 'approval_request_id'))->toBe('uuid');

    (require __DIR__.'/../../database/migrations/0007_change_approval_request_id_to_bigint.php')->up();

    expect(pgsqlApprovalsColumnType('approvals', 'approval_request_id'))->toBe('bigint');

    // Its (type, id) index survives the change.
    $indexes = collect(Schema::getIndexes('approvals'))->pluck('columns')->all();

    expect($indexes)->toContain(['approval_request_type', 'approval_request_id']);
})->skip($pgsqlOnly, 'the column type only diverges on postgres');

it('changes nothing it does not need to, and nothing on a second run', function (string $keyType): void {
    config()->set('approvals.key_type', $keyType);

    dropApprovalsTables();
    runApprovalsMigrations();

    $before = Schema::getColumns('approvals');

    (require __DIR__.'/../../database/migrations/0007_change_approval_request_id_to_bigint.php')->up();

    expect(Schema::getColumns('approvals'))->toBe($before);
})->with(['bigint', 'uuid', 'ulid']);

it('refuses to migrate on an unrecognized key type instead of falling back to bigint', function (): void {
    config()->set('approvals.key_type', 'nonsense');

    dropApprovalsTables();

    // A typo in a host's config must stop the migration, never silently build bigint
    // columns for a uuid/ulid-keyed host.
    expect(function (): void {
        runApprovalsMigrations();
    })->toThrow(InvalidConfigurationException::class, 'Configuration value [approvals.key_type] must be one of [bigint, uuid, ulid] (case-insensitive), [nonsense] given.');
});
