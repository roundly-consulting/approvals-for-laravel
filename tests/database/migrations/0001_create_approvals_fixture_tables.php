<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host-owned tables the suite's approvable/actor fixtures live in.
 *
 * These were three inline `Schema::create()` calls in the old TestCase's
 * `defineDatabaseMigrations()`. They are a migration now because the base case
 * resets a real engine by dropping every table and re-migrating — a table created
 * outside the migrator would be dropped on the first teardown and never come back,
 * so every test after the first would fail on the pgsql leg.
 *
 * No `down()`: packages migrate forward only, and the reset never calls one.
 */
return new class extends Migration
{
    public function up(): void
    {
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
};
