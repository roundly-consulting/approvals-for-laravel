<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('approvals.key_type');

        Schema::create('approval_delegations', function (Blueprint $table) use ($keyType): void {
            $table->id();
            // The approver handing over their authority.
            $table->morphKey('delegator', $keyType, nullable: false);
            // The model the authority is handed to.
            $table->morphKey('delegate', $keyType, nullable: false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable()->index();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(
                ['delegate_id', 'delegate_type', 'delegator_id', 'delegator_type'],
                'approval_delegations_delegate_delegator_index',
            );
        });
    }
};
