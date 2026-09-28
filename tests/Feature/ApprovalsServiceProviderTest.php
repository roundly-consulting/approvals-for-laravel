<?php

declare(strict_types=1);

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Approvals\Commands\ExpireApprovalsCommand;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

it('merges the package config', function (): void {
    expect(config('approvals.model'))->toBe(Approval::class)
        ->and(config('approvals.request_model'))->toBe(ApprovalRequest::class)
        ->and(config('approvals.stage_model'))->toBe(ApprovalRequestStage::class)
        ->and(config('approvals.delegation_model'))->toBe(ApprovalDelegation::class)
        ->and(config('approvals.default_status'))->toBe('approved');
});

it('registers the facade alias', function (): void {
    expect(AliasLoader::getInstance()->getAliases())->toHaveKey('Approvals')
        ->and(AliasLoader::getInstance()->getAliases()['Approvals'])->toBe(Approvals::class);
});

it('registers the expire command', function (): void {
    expect(Artisan::all())->toHaveKey('approvals:expire')
        ->and(Artisan::all()['approvals:expire'])->toBeInstanceOf(ExpireApprovalsCommand::class);
});

it('registers every publish tag', function (string $tag): void {
    expect(ServiceProvider::pathsToPublish(ApprovalsServiceProvider::class, $tag))->not->toBeEmpty();
})->with([
    'approvals-config',
    'approvals-migrations',
]);

it('publishes the config file', function (): void {
    $paths = ServiceProvider::pathsToPublish(ApprovalsServiceProvider::class, 'approvals-config');

    expect($paths)->toBe([
        realpath(__DIR__.'/../../config/approvals.php') => config_path('approvals.php'),
    ]);
});

// The hand-rolled "never auto-loads its migrations" check that lived here is gone: it
// re-implemented `toNotAutoLoadMigrations()` (now in tests/Feature/MigrationOrderTest.php)
// by reading `app('migrator')->paths()` itself. Proven equivalent rather than assumed —
// auto-loading the directory in boot() turned both red together.
//
// The publish check's count and timestamped-destination halves are likewise now
// `toPublishMigrationsTimestamped('approvals-migrations', 6)`. What remains below is the
// half no preset covers.

it('publishes migrations whose timestamps preserve the dependency order', function (): void {
    $paths = ServiceProvider::pathsToPublish(ApprovalsServiceProvider::class, 'approvals-migrations');

    $sources = array_map(basename(...), array_keys($paths));
    $targets = array_map(basename(...), array_values($paths));

    // The package's sources are numerically prefixed so the directory's sort order IS
    // the order the migrator must run them in: a table is created before anything
    // alters it. tests/Feature/MigrationOrderTest.php pins that order *structurally*
    // (`toHaveRunnableMigrationOrder`) against the source directory.
    expect($sources)->toBe([
        '0001_create_approvals_table.php',
        '0002_create_approval_requests_table.php',
        '0003_add_v11_columns_to_approvals_table.php',
        '0004_add_staging_to_approval_requests_table.php',
        '0005_create_approval_request_stages_table.php',
        '0006_create_approval_delegations_table.php',
    ]);

    // This is the host-facing other half, and the reason it stays: the structural pin
    // proves the *source* order is runnable, but a host runs the *published* files. The
    // published timestamps must step forward one file at a time so the host's migrator
    // reproduces that order — otherwise approvals #2 (an ALTER before its CREATE) ships
    // to the host despite a green source-order pin. No preset expresses this.
    $sorted = $targets;
    sort($sorted);

    expect($sorted)->toBe($targets)
        ->and($targets[2])->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_0003_add_v11_columns_to_approvals_table\.php$/');
});

it('contributes an approvals section to about', function (string $expected): void {
    $this->artisan('about --only=approvals')
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'Approvals',
    'Model',
    'Request model',
    'Stage model',
    'Delegation model',
    'Default status',
    'Authorization',
    'Ability',
    'Default expiry',
    'Workflow presets',
]);

it('reports the configured models by base name in about', function (): void {
    $this->artisan('about --only=approvals')
        ->expectsOutputToContain('Approval')
        ->assertExitCode(0);
});

it('reports authorization and expiry state in about', function (): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.expiry.default', 3600);

    $this->artisan('about --only=approvals')
        ->expectsOutputToContain('ENFORCED')
        ->expectsOutputToContain('3600s')
        ->assertExitCode(0);
});

it('reports an env-string authorization flag as enforced in about', function (): void {
    config()->set('approvals.authorization.enabled', '1');

    $this->artisan('about --only=approvals')
        ->expectsOutputToContain('ENFORCED')
        ->assertExitCode(0);
});

it('reports an unconfigured expiry as never', function (): void {
    $this->artisan('about --only=approvals')
        ->expectsOutputToContain('NEVER')
        ->assertExitCode(0);
});

it('never leaks the host gate ability or its workflow names in about', function (): void {
    config()->set('approvals.authorization.ability', 'approve-hostile-takeover');
    config()->set('approvals.workflows', [
        'series-c-payout' => ['rule' => 'unanimous'],
        'layoff' => ['rule' => 'any'],
    ]);

    $this->artisan('about --only=approvals')
        ->doesntExpectOutputToContain('approve-hostile-takeover')
        ->doesntExpectOutputToContain('series-c-payout')
        ->doesntExpectOutputToContain('layoff')
        ->expectsOutputToContain('2 defined')
        ->assertExitCode(0);
});

it('reports the shipped ability and empty workflows as default and none', function (): void {
    $this->artisan('about --only=approvals')
        ->expectsOutputToContain('DEFAULT')
        ->expectsOutputToContain('NONE')
        ->assertExitCode(0);
});
