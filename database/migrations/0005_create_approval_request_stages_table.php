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
        Schema::create('approval_request_stages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('approval_request_id')->index();
            $table->unsignedInteger('position');
            $table->string('name')->nullable();
            $table->string('rule');
            $table->unsignedInteger('quorum')->nullable();
            $table->unsignedInteger('required_approvers')->nullable();
            $table->string('status')->default(ApprovalStatus::Pending->value)->index();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('cleared_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['approval_request_id', 'position']);
        });
    }
};
