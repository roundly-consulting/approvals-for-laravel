<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\PackageToolkit\Enums\DatabaseDriver;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Approvals ships four CREATEs and three ALTERs, and zero foreign keys — every owner is a
 * polymorphic `morphs()`, deliberately unconstrained because a host's approvable and
 * actor can live in any table.
 *
 * This file replaces a hand-rolled reinvention: the suite used to copy the published
 * files into a temp directory and run `migrate` against a throwaway SQLite database. It
 * checked the right ideas with the wrong engine — SQLite is the driver that cannot fail
 * this class of check — and re-implemented, in this package, what the testing package
 * now ships once.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * M — the structural order pin.
 *
 * Worth being explicit about why this IS adopted on a zero-FK package, because the
 * shorthand "M = packages with FK edges" would say otherwise. `assertRunnable()` checks
 * two independent things, and only one of them is about foreign keys: it also pins that
 * every `Schema::table()` ALTER sorts at or after the CREATE of the table it alters.
 * Approvals ships three ALTERs — and approvals #2, an ALTER that sorted before its CREATE,
 * is the *named* bug that half of the assertion was built for. So the FK count here is 0
 * and the assertion still has real work to do.
 *
 * `foreignKeys: 0` is a live pin, not a formality: the day a real FK is added, this goes
 * red until the count is updated deliberately — which is what stops an edge from being
 * introduced without anyone thinking about its order.
 */
it('has a runnable migration order', function () use ($migrations): void {
    expect($migrations)->toHaveRunnableMigrationOrder(foreignKeys: 0);
});

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5,
 * on three packages). `count: 7` pins the file count so neither check can pass over an
 * empty or relocated directory.
 */
it('never auto-loads its migrations — the host publishes them', function (): void {
    expect(ApprovalsServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes every migration timestamp-injected into the host', function (): void {
    expect(ApprovalsServiceProvider::class)->toPublishMigrationsTimestamped('approvals-migrations', 7);
});

/**
 * R — the real-engine proof. Approvals' DDL had never met a real engine before this row.
 * `migrations: 7` pins the count, and the expectation additionally fails a set that
 * "applies cleanly" while creating no tables — an empty `up()` otherwise passes and
 * proves nothing.
 *
 * The negative control (`toRejectBrokenOrderOnConnection`) is deliberately NOT adopted:
 * it asserts the engine *refuses* a reordered set, and with zero foreign keys Postgres
 * has nothing to refuse — it would fail loudly by design. That is the assertion working
 * correctly against a shape it does not fit, not a red to chase. The ALTER ordering it
 * would have caught is covered structurally by the pin above, on every driver.
 */
it('applies its migrations on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 7);
})->skip(fn (): bool => ! test()->connectionAvailable('pgsql'), 'no postgres connection available');

/**
 * MySQL caps an InnoDB index at 3072 bytes and counts four bytes per utf8mb4 character, a
 * limit sqlite and postgres do not have. Until 1.1.1 the actor/approvable index also
 * covered `status` and came to 3076 bytes, so a fresh `migrate` on MySQL 8 died with
 * SQLSTATE 1071 on the first migration.
 */
it('applies its migrations on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', migrations: 7);
})->skip(fn (): bool => ! test()->connectionAvailable('mysql'), 'no mysql connection available');

it('keeps the actor/approvable index to the four morph columns', function (): void {
    $index = collect(Schema::getIndexes('approvals'))
        ->firstWhere('name', 'approvals_actor_approvable_status_index');

    // `status` stays out: it has its own index, and with it the key no longer fits
    // MySQL's 3072-byte limit under utf8mb4.
    expect($index['columns'] ?? null)->toBe(['actor_id', 'actor_type', 'approvable_id', 'approvable_type']);
});

/**
 * The driver-truth pin. It compares the driver the leg *declares* (TESTING_DB_DRIVER)
 * against what the connection itself *answers*, so a "pgsql" job that quietly ran on
 * SQLite — the exact failure the whole leg exists to prevent — is impossible rather than
 * merely detectable by reading a skip count.
 */
it('runs on the driver the leg declares', function (): void {
    expect(DatabaseDriver::current())->toBe(DatabaseDriver::from(DriverMatrix::driver()));
});

/**
 * The enum-backed `status` column and the morph columns are what the drivers render
 * differently. Pinning a round-trip on whatever engine the leg configured proves the
 * columns are usable rather than merely creatable.
 */
it('round-trips a decision on the configured engine', function (): void {
    $release = ReleaseTestModel::query()->create();
    $actor = ActorTestModel::query()->create();

    $request = $release->requestApproval([$actor], ApprovalRule::Unanimous);
    $approval = $actor->approve($release, 'ship it');

    $fresh = $approval->fresh();

    expect($fresh->reason)->toBe('ship it')
        ->and($fresh->status->value)->toBe('approved')
        ->and($fresh->approvable_type)->toBe($release->getMorphClass())
        ->and($request->fresh()->rule)->toBe(ApprovalRule::Unanimous)
        ->and($release->hasBeenApprovedBy($actor))->toBeTrue()
        ->and(DB::connection()->getDriverName())->toBe(DriverMatrix::driver());
});
