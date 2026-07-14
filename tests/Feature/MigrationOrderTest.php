<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;

/**
 * The package ships four CREATEs and two ALTERs. Publishing preserves the source
 * directory's order, so that order has to be runnable end to end: every table
 * must exist before anything alters it. These tests run the *published* files —
 * under their published names, into a database that starts empty — which is
 * exactly what a host does.
 */
beforeEach(function (): void {
    $this->publishedPath = sys_get_temp_dir().'/approvals-migration-order-'.bin2hex(random_bytes(6));
    $this->publishedDatabase = $this->publishedPath.'/database.sqlite';

    File::makeDirectory($this->publishedPath, recursive: true);
    File::put($this->publishedDatabase, '');

    // Copy every source to the filename it publishes under, so the migrator sees
    // precisely what lands in a host's database/migrations directory.
    foreach (ServiceProvider::pathsToPublish(ApprovalsServiceProvider::class, 'approvals-migrations') as $source => $target) {
        File::copy($source, $this->publishedPath.'/'.basename((string) $target));
    }

    config()->set('database.connections.published', [
        'driver' => 'sqlite',
        'database' => $this->publishedDatabase,
        'prefix' => '',
    ]);
});

afterEach(function (): void {
    File::deleteDirectory($this->publishedPath);
});

it('migrates the published files clean from an empty database', function (): void {
    $schema = Schema::connection('published');

    expect($schema->hasTable('approvals'))->toBeFalse();

    $this->artisan('migrate', [
        '--database' => 'published',
        '--path' => $this->publishedPath,
        '--realpath' => true,
    ])->assertExitCode(0);

    expect($schema->hasTable('approvals'))->toBeTrue()
        ->and($schema->hasTable('approval_requests'))->toBeTrue()
        ->and($schema->hasTable('approval_request_stages'))->toBeTrue()
        ->and($schema->hasTable('approval_delegations'))->toBeTrue();

    // The two ALTERs ran against tables that already existed.
    expect($schema->hasColumns('approvals', ['decided_by_id', 'decided_by_type', 'weight', 'approval_request_stage_id']))->toBeTrue()
        ->and($schema->hasColumns('approval_requests', ['staged', 'reject_on_stage_rejection', 'workflow']))->toBeTrue();
});

it('publishes every migration under a name that sorts after the table it depends on', function (): void {
    $published = array_map(
        static fn (string $target): string => basename($target),
        array_values(ServiceProvider::pathsToPublish(ApprovalsServiceProvider::class, 'approvals-migrations')),
    );

    $position = static function (string $needle) use ($published): int {
        foreach ($published as $index => $name) {
            if (str_contains($name, $needle)) {
                return $index;
            }
        }

        return -1;
    };

    // Each ALTER must sort after the CREATE of the table it alters.
    expect($position('create_approvals_table'))->toBeLessThan($position('add_v11_columns_to_approvals_table'))
        ->and($position('create_approval_requests_table'))->toBeLessThan($position('add_staging_to_approval_requests_table'));
});
