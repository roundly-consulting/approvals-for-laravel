<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

afterEach(fn () => CarbonImmutable::setTestNow());

it('excludes a future-starting delegation from the active scope', function (): void {
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    ApprovalDelegation::factory()->create(['starts_at' => CarbonImmutable::now()->addHour()]);

    expect(ApprovalDelegation::query()->active()->count())->toBe(0);
});

it('revokes a delegation at an explicit moment', function (): void {
    $delegation = ApprovalDelegation::factory()->create();

    $delegation->revoke(CarbonImmutable::parse('2026-06-19 10:00:00'));

    expect($delegation->revoked_at?->toDateString())->toBe('2026-06-19');
});

it('exposes the request relation and open state on a stage', function (): void {
    $request = ApprovalRequest::factory()->staged()->create();
    $stage = ApprovalRequestStage::factory()->forRequest($request)->create();

    expect($stage->request)->toBeInstanceOf(ApprovalRequest::class)
        ->and($stage->isOpen())->toBeTrue();

    $closed = ApprovalRequestStage::factory()->forRequest($request)->cleared()->create();

    expect($closed->isOpen())->toBeFalse();
});

it('does not re-open an already opened stage', function (): void {
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    $request = ApprovalRequest::factory()->staged()->create();
    $stage = ApprovalRequestStage::factory()->forRequest($request)->create([
        'opened_at' => CarbonImmutable::parse('2026-06-19 09:00:00'),
    ]);

    $stage->markOpened();

    expect($stage->opened_at?->format('H'))->toBe('09');
});

it('returns no current stage for a non-staged request', function (): void {
    $request = ApprovalRequest::factory()->create();

    expect($request->currentStage())->toBeNull();
});

it('reports flat progress for a quorum request', function (): void {
    $request = ApprovalRequest::factory()->quorum(2)->create(['required_approvers' => 3]);

    $progress = $request->approvalProgress();

    expect($progress->status)->toBe(ApprovalStatus::Pending)
        ->and($progress->required)->toBe(3)
        ->and($progress->threshold)->toBe(2)
        ->and($progress->totalStages)->toBe(0)
        ->and($progress->ratio())->toBe(0.0);
});

it('computes an any-rule flat progress ratio without a threshold', function (): void {
    $request = ApprovalRequest::factory()->any()->create([
        'required_approvers' => 0,
        'status' => ApprovalStatus::Approved,
    ]);

    expect($request->approvalProgress()->ratio())->toBe(1.0);
});

it('reports zero ratio for an unmet any-rule with no target', function (): void {
    $request = ApprovalRequest::factory()->any()->create(['required_approvers' => 0]);

    expect($request->approvalProgress()->ratio())->toBe(0.0);
});

it('flags weighted-resolving on the rule enum', function (): void {
    expect(ApprovalRule::Weighted->isWeighted())->toBeTrue();
});
