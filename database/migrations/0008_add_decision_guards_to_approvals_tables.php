<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The columns behind the opt-in decision guards. All nullable, so existing rounds and
 * decisions keep working exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            // The actors the round's opener excluded from deciding it (the requester, say),
            // as a list of {type, id}.
            $table->json('excluded_approvers')->nullable();

            // The subject's ProvidesApprovalFingerprint value when the round opened.
            $table->string('subject_fingerprint')->nullable();
        });

        Schema::table('approvals', function (Blueprint $table): void {
            // The subject's fingerprint at the moment of the decision: the version decided on.
            $table->string('subject_fingerprint')->nullable();
        });
    }
};
