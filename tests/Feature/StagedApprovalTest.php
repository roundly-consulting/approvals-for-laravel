<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStageCleared;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

it('opens stage one immediately and gates later stages', function (): void {
    $release = ReleaseTestModel::create();
    $eng1 = ReviewerTestModel::create();
    $eng2 = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng1, $eng2], ApprovalRule::Unanimous, name: 'engineering'),
        new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
    ]);

    expect($product)->not->toBeNull();

    expect($release->currentStage()?->position)->toBe(1);

    // One engineering approval is not enough to clear a unanimous stage of two.
    $eng1->approve($release);
    expect($release->currentStage()?->position)->toBe(1)
        ->and($release->isApproved())->toBeFalse();

    $eng2->approve($release);

    // Stage one cleared; stage two is now open.
    expect($release->fresh()->currentStage()?->position)->toBe(2);
});

it('resolves once the final stage clears', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any),
        new StageDefinition([$product], ApprovalRule::Any),
    ]);

    $eng->approve($release);
    expect($release->isApproved())->toBeFalse();

    $product->approve($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('rejects the request when a stage is rejected by default', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Unanimous),
        new StageDefinition([$product], ApprovalRule::Any),
    ]);

    $eng->reject($release, 'blocked');

    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});

it('continues past a rejected stage when configured not to reject', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Unanimous),
        new StageDefinition([$product], ApprovalRule::Any),
    ], rejectOnStageRejection: false);

    $eng->reject($release);

    // The pipeline moves on to stage two rather than rejecting.
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending)
        ->and($release->fresh()->currentStage()?->position)->toBe(2);

    $product->approve($release);

    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('opens the stage after a rejected one with its event when continuing', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
        new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
    ], rejectOnStageRejection: false);

    Event::fake([ApprovalStageOpened::class]);

    $eng->reject($release);

    Event::assertDispatched(ApprovalStageOpened::class, fn (ApprovalStageOpened $event): bool => $event->stage->name === 'product');
});

it('dispatches stage opened and cleared events', function (): void {
    Event::fake();

    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any),
        new StageDefinition([$product], ApprovalRule::Any),
    ]);

    $eng->approve($release);

    Event::assertDispatched(ApprovalStageOpened::class);
    Event::assertDispatched(ApprovalStageCleared::class);
});

it('reports staged progress', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any),
        new StageDefinition([$product], ApprovalRule::Any),
    ]);

    $progress = $release->approvalProgress();

    expect($progress)->not->toBeNull()
        ->and($progress->totalStages)->toBe(2)
        ->and($progress->clearedStages)->toBe(0)
        ->and($progress->currentStage)->toBe(1);

    $eng->approve($release);

    $progress = $release->fresh()->approvalProgress();

    expect($progress->clearedStages)->toBe(1)
        ->and($progress->currentStage)->toBe(2)
        ->and($progress->percentage())->toBe(50);
});

it('attaches decisions to the open stage', function (): void {
    $release = ReleaseTestModel::create();
    $eng = ReviewerTestModel::create();

    $release->requestStagedApproval([
        new StageDefinition([$eng], ApprovalRule::Any),
    ]);

    $stage = $release->currentStage();

    $eng->approve($release);

    expect($stage->fresh()->decisions()->count())->toBe(1);
});

it('stamps an expiry on a staged request opened through the trait', function (): void {
    $release = ReleaseTestModel::create();
    $expires = now()->addWeek()->startOfSecond();

    $request = $release->requestStagedApproval(
        [new StageDefinition([ReviewerTestModel::create()], ApprovalRule::Any)],
        rejectOnStageRejection: false,
        expiresAt: $expires,
    );

    expect($request->expires_at?->equalTo($expires))->toBeTrue()
        ->and($request->reject_on_stage_rejection)->toBeFalse();
});

it('retires a stage\'s pending asks when it settles', function (): void {
    $release = ReleaseTestModel::create();
    [$a, $b, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

    $request = $release->requestStagedApproval([
        new StageDefinition([$a, $b], ApprovalRule::Any),
        new StageDefinition([$c], ApprovalRule::Any),
    ]);

    $ask = Approvals::for($release)->as($b)->ask();

    Event::fake([ApprovalCancelled::class]);

    $a->approve($release);

    expect($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
        ->and($release->currentStage()?->position)->toBe(2)
        ->and($release->pendingApprovals())->toHaveCount(0)
        ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
        ->and($ask->fresh()?->live)->toBeNull();

    Event::assertDispatchedTimes(ApprovalCancelled::class, 1);
});

it('keeps the open stage\'s asks when an earlier stage settles', function (): void {
    $release = ReleaseTestModel::create();
    [$a, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    $release->requestStagedApproval([
        new StageDefinition([$a], ApprovalRule::Any),
        new StageDefinition([$c], ApprovalRule::Any),
    ]);

    $a->approve($release);

    $ask = Approvals::for($release)->as($c)->ask();

    expect($ask->status)->toBe(ApprovalStatus::Pending)
        ->and($release->pendingApprovals())->toHaveCount(1);
});
