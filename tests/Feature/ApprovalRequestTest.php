<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

it('resolves a unanimous request after every approver approves', function (): void {
    $release = ReleaseTestModel::create();
    $lead = ReviewerTestModel::create();
    $qa = ReviewerTestModel::create();
    $pm = ReviewerTestModel::create();

    $release->requestApproval([$lead, $qa, $pm], ApprovalRule::Unanimous);

    $lead->approve($release);
    expect($release->isApproved())->toBeFalse();

    $qa->approve($release);
    expect($release->isApproved())->toBeFalse();

    $pm->approve($release);
    expect($release->isApproved())->toBeTrue()
        ->and($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('rejects a unanimous request on the first rejection', function (): void {
    $release = ReleaseTestModel::create();
    $lead = ReviewerTestModel::create();
    $qa = ReviewerTestModel::create();

    $release->requestApproval([$lead, $qa], ApprovalRule::Unanimous);

    $lead->reject($release, 'blocker');

    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});

it('resolves a quorum request once the quorum is reached', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();
    $b = ReviewerTestModel::create();
    $c = ReviewerTestModel::create();

    $release->requestApproval([$a, $b, $c], ApprovalRule::Quorum, quorum: 2);

    $a->approve($release);
    expect($release->isApproved())->toBeFalse();

    $b->approve($release);
    expect($release->isApproved())->toBeTrue();
});

it('rejects a quorum request once it becomes unreachable', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();
    $b = ReviewerTestModel::create();
    $c = ReviewerTestModel::create();

    $release->requestApproval([$a, $b, $c], ApprovalRule::Quorum, quorum: 2);

    $a->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    $b->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});

it('resolves an any request after the first approval', function (): void {
    Event::fake();

    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();
    $b = ReviewerTestModel::create();

    $release->requestApproval([$a, $b], ApprovalRule::Any);

    $a->approve($release);

    expect($release->isApproved())->toBeTrue();
    Event::assertDispatched(ApprovalRequestResolved::class, 1);
});

it('rejects an any request only when everyone rejects', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();
    $b = ReviewerTestModel::create();

    $release->requestApproval([$a, $b], ApprovalRule::Any);

    $a->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    $b->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});

it('reports pending approval state', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();

    expect($release->isPendingApproval())->toBeTrue();

    $release->requestApproval([$a], ApprovalRule::Any);

    expect($release->isPendingApproval())->toBeTrue();

    $a->approve($release);

    expect($release->isPendingApproval())->toBeFalse();
});

it('lists approval requests for a subject', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();

    $release->requestApproval([$a], ApprovalRule::Any);

    expect($release->approvalRequests)->toHaveCount(1);
});
