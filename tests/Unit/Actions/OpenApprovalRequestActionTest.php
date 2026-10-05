<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Actions\OpenApprovalRequestAction;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
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

    // No names: an open request, whose headcount only the override can state.
    $request = app(OpenApprovalRequestAction::class)->execute(new ApprovalRequestData(
        subject: ReleaseTestModel::create(),
        approvers: [],
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

it('refuses an unnamed request that needs no approvals', function (ApprovalRule $rule): void {
    app(OpenApprovalRequestAction::class)->execute(new ApprovalRequestData(ReleaseTestModel::create(), [], $rule));
})->with([ApprovalRule::Unanimous, ApprovalRule::Quorum, ApprovalRule::Weighted])
    ->throws(InvalidApprovalRequestException::class, 'needs at least one approval');

it('refuses a request whose required approvals were set to zero', function (): void {
    app(OpenApprovalRequestAction::class)->execute(
        new ApprovalRequestData(ReleaseTestModel::create(), [ReviewerTestModel::create()], requiredApprovers: 0),
    );
})->throws(InvalidApprovalRequestException::class, 'An approval request under the [unanimous] rule needs at least one approval to resolve');

it('refuses the builder\'s unnamed request under the default rule, and writes nothing', function (): void {
    $release = ReleaseTestModel::create();

    expect(fn () => Approvals::request($release)->open())
        ->toThrow(InvalidApprovalRequestException::class, 'needs at least one approval')
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('still opens the unnamed requests that can resolve', function (): void {
    $release = ReleaseTestModel::create();

    expect(Approvals::request($release)->any()->open()->status)->toBe(ApprovalStatus::Pending)
        ->and(Approvals::request(ReleaseTestModel::create())->quorum(2)->open()->quorum)->toBe(2)
        ->and(app(OpenApprovalRequestAction::class)->execute(
            new ApprovalRequestData(ReleaseTestModel::create(), [], requiredApprovers: 2),
        )->required_approvers)->toBe(2);
});
