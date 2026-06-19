<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\WeightedReviewerTestModel;

it('resolves on summed weight rather than headcount', function (): void {
    $release = ReleaseTestModel::create();

    $director = WeightedReviewerTestModel::create();
    $director->weight = 3;

    $analyst = WeightedReviewerTestModel::create();
    $analyst->weight = 1;

    $release->requestApproval([$director, $analyst], ApprovalRule::Weighted, quorum: 3);

    // A single weight-3 director already meets the threshold of 3.
    $director->approve($release);

    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('keeps plain quorum as headcount when no weights are given', function (): void {
    $release = ReleaseTestModel::create();
    $a = WeightedReviewerTestModel::create();
    $b = WeightedReviewerTestModel::create();
    $c = WeightedReviewerTestModel::create();

    $release->requestApproval([$a, $b, $c], ApprovalRule::Quorum, quorum: 2);

    $a->approve($release);
    expect($release->isApproved())->toBeFalse();

    $b->approve($release);
    expect($release->isApproved())->toBeTrue();
});

it('records the resolved weight on the decision', function (): void {
    $release = ReleaseTestModel::create();
    $heavy = WeightedReviewerTestModel::create();
    $heavy->weight = 5;

    $release->requestApproval([$heavy], ApprovalRule::Weighted, quorum: 5);

    $approval = $heavy->approve($release);

    expect($approval->weight)->toBe(5);
});

it('rejects a weighted request once the threshold is unreachable', function (): void {
    $release = ReleaseTestModel::create();

    $a = WeightedReviewerTestModel::create();
    $a->weight = 1;
    $b = WeightedReviewerTestModel::create();
    $b->weight = 1;
    $c = WeightedReviewerTestModel::create();
    $c->weight = 1;

    $release->requestApproval([$a, $b, $c], ApprovalRule::Weighted, quorum: 2);

    $a->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    $b->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});
