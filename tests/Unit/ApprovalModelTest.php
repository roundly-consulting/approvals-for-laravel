<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Approvals\Models\Approval;

it('builds an approval through its factory', function (): void {
    $approval = Approval::factory()->create();

    expect($approval)
        ->toBeInstanceOf(Approval::class)
        ->actor_type->not->toBeEmpty()
        ->approvable_type->not->toBeEmpty();

    $this->assertDatabaseHas('approvals', ['id' => $approval->id]);
});

it('soft deletes an approval', function (): void {
    $approval = Approval::factory()->create();

    $approval->delete();

    expect(Approval::query()->find($approval->id))->toBeNull()
        ->and(Approval::withTrashed()->find($approval->id))->not->toBeNull();

    $this->assertSoftDeleted('approvals', ['id' => $approval->id]);
});

it('exposes the actor and approvable morph relations', function (): void {
    $approval = new Approval;

    expect($approval->actor())->toBeInstanceOf(MorphTo::class)
        ->and($approval->approvable())->toBeInstanceOf(MorphTo::class);
});
