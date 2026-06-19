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
        Schema::create('approval_requests', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('subject');
            $table->string('rule');
            $table->unsignedInteger('quorum')->nullable();
            $table->unsignedInteger('required_approvers')->nullable();
            $table->string('status')->default(ApprovalStatus::Pending->value)->index();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }
};
