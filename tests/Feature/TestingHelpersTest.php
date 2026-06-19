<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Testing\ApprovalExpectations;
use RoundlyConsulting\Approvals\Testing\InteractsWithApprovals;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

uses(InteractsWithApprovals::class);

beforeEach(fn () => ApprovalExpectations::register());

it('registers and uses the toBeApproved expectation', function (): void {
    $deployment = DeploymentTestModel::create();
    $reviewer = ReviewerTestModel::create();

    $this->actingAsApprover($reviewer)->approveAs($deployment);

    expect($deployment)->toBeApproved();
});

it('registers and uses the toBeRejected expectation', function (): void {
    $deployment = DeploymentTestModel::create();
    $reviewer = ReviewerTestModel::create();

    $this->rejectAs($deployment, 'no', $reviewer);

    expect($deployment)->toBeRejected();
});

it('registers and uses the toBePendingApproval expectation', function (): void {
    $release = ReleaseTestModel::create();
    $reviewer = ReviewerTestModel::create();

    $release->requestApproval([$reviewer]);

    expect($release)->toBePendingApproval();
});

it('reads status from a request-backed subject', function (): void {
    $release = ReleaseTestModel::create();
    $reviewer = ReviewerTestModel::create();

    $release->requestApproval([$reviewer]);
    $reviewer->approve($release);

    expect(ApprovalExpectations::statusOf($release))->toBe(
        ApprovalStatus::Approved,
    );
});

it('throws when approving without an actor set', function (): void {
    $deployment = DeploymentTestModel::create();

    expect(fn () => $this->approveAs($deployment))->toThrow(RuntimeException::class);
});
