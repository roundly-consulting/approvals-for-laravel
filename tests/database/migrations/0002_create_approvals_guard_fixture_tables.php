<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host-owned fixtures for the decision guards: a subject with a version to fingerprint
 * and an author to exclude, and an approver that can be soft-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('amount')->default(100);
            $table->unsignedInteger('author_id')->nullable();
        });

        Schema::create('members', function (Blueprint $table): void {
            $table->increments('id');
            $table->softDeletes();
        });
    }
};
