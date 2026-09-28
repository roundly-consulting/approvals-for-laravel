<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Actions\OpenApprovalRequestAction;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

it('opens a pending flat request for the subject', function (): void {
    $release = ReleaseTestModel::create();
    $approvers = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    $request = app(OpenApprovalRequestAction::class)->execute(new ApprovalRequestData($release, $approvers));

    expect($request->exists)->toBeTrue()
        ->and($request->subject?->is($release))->toBeTrue()
        ->and($request->status)->toBe(ApprovalStatus::Pending)
        ->and($request->rule)->toBe(ApprovalRule::Unanimous)
        ->and($request->quorum)->toBeNull()
        ->and($request->required_approvers)->toBe(2)
        ->and($request->workflow)->toBeNull()
        ->and($request->expires_at)->toBeNull();
});

it('honours rule, quorum, expiry, headcount override and workflow name', function (): void {
    $expires = CarbonImmutable::now()->addDay()->startOfSecond();

    $request = app(OpenApprovalRequestAction::class)->execute(new ApprovalRequestData(
        subject: ReleaseTestModel::create(),
        approvers: [ReviewerTestModel::create()],
        rule: ApprovalRule::Weighted,
        quorum: 5,
        expiresAt: $expires,
        requiredApprovers: 7,
        workflow: 'payout',
    ));

    expect($request->rule)->toBe(ApprovalRule::Weighted)
        ->and($request->quorum)->toBe(5)
        ->and($request->required_approvers)->toBe(7)
        ->and($request->workflow)->toBe('payout')
        ->and($request->expires_at?->equalTo($expires))->toBeTrue();
});
