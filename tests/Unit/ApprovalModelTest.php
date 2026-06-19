<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Models\Approval;

it('builds an approval through its factory', function (): void {
    $approval = Approval::factory()->create();

    expect($approval)
        ->toBeInstanceOf(Approval::class)
        ->actor_type->not->toBeEmpty()
        ->approvable_type->not->toBeEmpty()
        ->status->toBe(ApprovalStatus::Approved);

    $this->assertDatabaseHas('approvals', ['id' => $approval->id]);
});

it('soft deletes an approval', function (): void {
    $approval = Approval::factory()->create();

    $approval->delete();

    expect(Approval::query()->find($approval->id))->toBeNull()
        ->and(Approval::withTrashed()->find($approval->id))->not->toBeNull();

    $this->assertSoftDeleted('approvals', ['id' => $approval->id]);
});

it('exposes the morph relations', function (): void {
    $approval = new Approval;

    expect($approval->actor())->toBeInstanceOf(MorphTo::class)
        ->and($approval->approvable())->toBeInstanceOf(MorphTo::class)
        ->and($approval->approvalRequest())->toBeInstanceOf(MorphTo::class);
});

it('casts status and dates', function (): void {
    $approval = Approval::factory()->approved()->create(['expires_at' => now()->addDay()]);
    $approval->refresh();

    expect($approval->status)->toBeInstanceOf(ApprovalStatus::class)
        ->and($approval->decided_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($approval->expires_at)->toBeInstanceOf(CarbonImmutable::class);
});

it('approves a pending approval', function (): void {
    $approval = Approval::factory()->pending()->create();

    $approval->approve('great');

    expect($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->reason)->toBe('great')
        ->and($approval->decided_at)->not->toBeNull();
});

it('rejects a pending approval', function (): void {
    $approval = Approval::factory()->pending()->create();

    $approval->reject('nope');

    expect($approval->status)->toBe(ApprovalStatus::Rejected)
        ->and($approval->reason)->toBe('nope');
});

it('cancels a pending approval', function (): void {
    $approval = Approval::factory()->pending()->create();

    $approval->cancel();

    expect($approval->status)->toBe(ApprovalStatus::Cancelled);
});

it('marks a pending approval expired', function (): void {
    $approval = Approval::factory()->pending()->create();

    $approval->markExpired();

    expect($approval->status)->toBe(ApprovalStatus::Expired);
});

it('rejects illegal transitions', function (): void {
    $approval = Approval::factory()->approved()->create();

    $approval->reject();
})->throws(InvalidStatusTransitionException::class);

it('filters by status scopes', function (): void {
    Approval::factory()->pending()->create();
    Approval::factory()->approved()->create();
    Approval::factory()->rejected()->create();
    Approval::factory()->expired()->create();

    expect(Approval::query()->pending()->count())->toBe(1)
        ->and(Approval::query()->approved()->count())->toBe(1)
        ->and(Approval::query()->rejected()->count())->toBe(1)
        ->and(Approval::query()->expired()->count())->toBe(1)
        ->and(Approval::query()->active()->count())->toBe(2);
});

it('filters by expiry scope', function (): void {
    Approval::factory()->pending()->create(['expires_at' => now()->subDay()]);
    Approval::factory()->pending()->create(['expires_at' => now()->addDay()]);
    Approval::factory()->pending()->create(['expires_at' => null]);

    expect(Approval::query()->expiringBefore(now())->count())->toBe(1);
});
