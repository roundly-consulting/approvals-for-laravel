<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table): void {
            $table->id();
            // The approver handing over their authority.
            $table->morphs('delegator');
            // The model the authority is handed to.
            $table->morphs('delegate');
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
