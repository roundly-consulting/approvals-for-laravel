<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        $keyType = KeyType::fromConfig('approvals.key_type');

        Schema::create('approvals', function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->morphKey('actor', $keyType, nullable: false);
            $table->morphKey('approvable', $keyType, nullable: false);
            $table->string('status')->default(ApprovalStatus::Approved->value)->index();
            $table->text('reason')->nullable();
            $table->morphKey('approval_request', $keyType, nullable: true);

            // The decision slot: which request (and stage) this decision belongs to — '' for
            // a standalone decision — so an actor holds one live decision per slot.
            $table->string('decision_scope', 64)->default('');

            // True while this is the actor's live decision in its slot (pending, approved or
            // rejected); NULL once it is withdrawn, superseded, expired or soft-deleted.
            // NULL rather than false so retired rows never collide in the unique index below.
            $table->boolean('live')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            // `status` is not part of this key: with it the index came to 3076 bytes under
            // utf8mb4, over MySQL's 3072-byte limit, so this migration could not run there.
            // `status` has its own index, and an actor holds only a handful of rows per
            // approvable. The name stays so fresh and upgraded installs share one index name.
            $table->index(
                ['actor_id', 'actor_type', 'approvable_id', 'approvable_type'],
                'approvals_actor_approvable_status_index',
            );

            // One live decision per actor, approvable and slot. Two concurrent approvals by
            // the same actor used to both pass the "already decided?" read and insert two
            // rows, counting the actor twice towards a quorum.
            $table->unique(
                ['approvable_type', 'approvable_id', 'actor_type', 'actor_id', 'decision_scope', 'live'],
                'approvals_live_decision_unique',
            );
        });
    }
};
