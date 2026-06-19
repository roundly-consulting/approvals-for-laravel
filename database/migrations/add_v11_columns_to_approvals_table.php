<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvals', function (Blueprint $table): void {
            // The model that physically made the decision when it differs from the
            // recorded actor (i.e. a delegate acting on behalf of the actor).
            $table->nullableMorphs('decided_by');

            // The weight this decision contributes towards a weighted/quorum threshold.
            $table->unsignedInteger('weight')->default(1);

            // The stage this decision belongs to, for staged (sequential) requests.
            $table->unsignedBigInteger('approval_request_stage_id')->nullable()->index();
        });
    }
};
