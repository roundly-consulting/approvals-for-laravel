<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table): void {
            // Marks a request as a multi-stage (sequential) pipeline.
            $table->boolean('staged')->default(false)->index();

            // Whether a rejection in any stage rejects the whole request.
            $table->boolean('reject_on_stage_rejection')->default(true);

            // The named workflow preset this request was built from, when any.
            $table->string('workflow')->nullable();
        });
    }
};
