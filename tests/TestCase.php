<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Migrations\Migration;
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

        $this->setUpDatabaseSchema();
    }

    protected function setUpDatabaseSchema(): void
    {
        Schema::dropAllTables();

        foreach (['create_approvals_table', 'create_approval_requests_table'] as $name) {
            $migration = require __DIR__."/../database/migrations/{$name}.php";

            if ($migration instanceof Migration) {
                $migration->up();
            }
        }

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
