<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStageCleared;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/**
 * Two decisions landing at once each resolve the request from their own in-memory copy.
 * Only the first may finalize it: the second used to finalize it again, firing
 * ApprovalRequestResolved twice and rewriting resolved_at.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

it('finalizes a request once when a stale copy resolves it again', function (): void {
    CarbonImmutable::setTestNow('2026-09-28 12:00:00');

    $release = ReleaseTestModel::create();
    [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    $request = $release->requestApproval([$a, $b], ApprovalRule::Any);

    // The other process loaded the request before this decision resolved it.
    $stale = ApprovalRequest::query()->findOrFail($request->getKey());

    Event::fake([ApprovalRequestResolved::class, ApprovalStatusChanged::class]);

    $a->approve($release);

    CarbonImmutable::setTestNow('2026-09-28 12:05:00');

    $stale->resolve();

    Event::assertDispatchedTimes(ApprovalRequestResolved::class, 1);

    expect(Event::dispatched(
        ApprovalStatusChanged::class,
        fn (ApprovalStatusChanged $event): bool => $event->subject instanceof ApprovalRequest,
    ))->toHaveCount(1)
        ->and($stale->status)->toBe(ApprovalStatus::Approved)
        ->and($request->fresh()?->resolved_at?->toDateTimeString())->toBe('2026-09-28 12:00:00');
});

it('settles a stage once when two copies settle it', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();

    $request = $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
        new StageDefinition([ReviewerTestModel::create()], ApprovalRule::Any, name: 'product'),
    ]);

    $stage = $request->currentStage();
    $copy = $stage?->fresh();

    expect($stage?->settle(ApprovalStatus::Approved))->toBeTrue()
        ->and($copy?->settle(ApprovalStatus::Approved))->toBeFalse()
        ->and($copy?->status)->toBe(ApprovalStatus::Approved);
});

it('clears a stage once when a stale request copy resolves it again', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();

    $request = $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
        new StageDefinition([ReviewerTestModel::create()], ApprovalRule::Any, name: 'product'),
    ]);

    $stale = ApprovalRequest::query()->findOrFail($request->getKey());

    Event::fake([ApprovalStageCleared::class]);

    $eng->approve($release);
    $stale->resolve();

    Event::assertDispatchedTimes(ApprovalStageCleared::class, 1);
});
