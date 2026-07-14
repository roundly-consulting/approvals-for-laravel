<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ApprovalsServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    /**
     * The package's migrations are publish-only — nothing is auto-discovered, so
     * the suite runs the package's own directory. Its files are ordered by a
     * numeric prefix, which is exactly the order the migrator needs.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('actors', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('deployments', function (Blueprint $table): void {
            $table->increments('id');
        });

        Schema::create('releases', function (Blueprint $table): void {
            $table->increments('id');
        });
    }
}
