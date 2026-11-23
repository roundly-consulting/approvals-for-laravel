<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

/**
 * Scoped delegations (2.4.0): a delegation may be limited to one context (a team, a
 * tenant). Nullable, so every existing delegation stays valid everywhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('approvals.key_type');

        Schema::table('approval_delegations', function (Blueprint $table) use ($keyType): void {
            // The context the delegation counts in; null counts everywhere.
            $table->morphKey('scope', $keyType, nullable: true);

            $table->index(
                ['delegate_id', 'delegate_type', 'scope_id', 'scope_type'],
                'approval_delegations_delegate_scope_index',
            );
        });
    }
};
