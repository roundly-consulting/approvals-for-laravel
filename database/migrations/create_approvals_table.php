<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table): void {
            $table->id();
            $table->morphs('actor');
            $table->morphs('approvable');
            $table->string('status')->default(ApprovalStatus::Approved->value)->index();
            $table->text('reason')->nullable();
            $table->nullableMorphs('approval_request');
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['actor_id', 'actor_type', 'approvable_id', 'approvable_type', 'status'],
                'approvals_actor_approvable_status_index',
            );
        });
    }
};
