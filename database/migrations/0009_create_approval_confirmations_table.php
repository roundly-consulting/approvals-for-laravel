<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Single-use confirmations (2.3.0): one named person confirms one exact action, bound to
 * its payload hash, once, before it expires. A table of its own, so nothing about the
 * approval rounds (their status, progress or sweeps) changes for a host that never asks
 * for a confirmation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('approvals.key_type');

        Schema::create('approval_confirmations', function (Blueprint $table) use ($keyType): void {
            $table->id();
            // What the confirmation is about (the tool call, the record the action touches).
            $table->morphKey('subject', $keyType, nullable: false);
            // The one person who may confirm or decline it.
            $table->morphKey('person', $keyType, nullable: false);
            // The hash of the exact action (tool + arguments) it was asked for.
            $table->string('payload_hash');
            $table->string('status')->default(ConfirmationStatus::Pending->value)->index();
            // The decline's reason.
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            // When it was confirmed, declined or lapsed.
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }
};
