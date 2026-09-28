<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\DelegateApprovalsAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalDelegated;
use RoundlyConsulting\Approvals\Events\ApprovalDelegationRevoked;
use RoundlyConsulting\Approvals\Exceptions\InvalidDelegationException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

afterEach(fn () => CarbonImmutable::setTestNow());

it('records a decision on behalf of the delegator', function (): void {
    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $manager->delegateApprovalsTo($assistant);

    $approval = $assistant->approve($deployment, 'covered while away');

    expect($approval->actor_id)->toBe($manager->getKey())
        ->and($approval->decided_by_id)->toBe($assistant->getKey())
        ->and($approval->wasDelegated())->toBeTrue()
        ->and($deployment->hasBeenApprovedBy($manager))->toBeTrue()
        ->and($deployment->hasBeenApprovedBy($assistant))->toBeFalse();
});

it('honors a time-windowed delegation and stops after it ends', function (): void {
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $manager->delegateApprovalsTo($assistant, until: CarbonImmutable::now()->addHour());

    // After the window the delegate decides as itself again.
    CarbonImmutable::setTestNow('2026-06-19 14:00:00');

    $approval = $assistant->approve($deployment);

    expect($approval->actor_id)->toBe($assistant->getKey())
        ->and($approval->wasDelegated())->toBeFalse();
});

it('ignores a not-yet-started delegation', function (): void {
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $manager->delegateApprovalsTo($assistant, from: CarbonImmutable::now()->addHour());

    $approval = $assistant->approve($deployment);

    expect($approval->actor_id)->toBe($assistant->getKey());
});

it('revokes an active delegation', function (): void {
    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $manager->delegateApprovalsTo($assistant);

    $revoked = $manager->revokeApprovalDelegation();

    expect($revoked)->toBe(1);

    $approval = $assistant->approve($deployment);

    expect($approval->actor_id)->toBe($assistant->getKey());
});

it('rejects self delegation', function (): void {
    $manager = ReviewerTestModel::create();

    expect(fn () => $manager->delegateApprovalsTo($manager))
        ->toThrow(InvalidDelegationException::class);
});

it('rejects a window that ends before it starts', function (): void {
    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();

    expect(fn () => app(DelegateApprovalsAction::class)->execute(
        $manager,
        $assistant,
        CarbonImmutable::now()->addDay(),
        CarbonImmutable::now(),
    ))->toThrow(InvalidDelegationException::class);
});

it('delegates through the facade', function (): void {
    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();

    $delegation = Approvals::delegations($manager)->to($assistant)->for(3600)->grant();

    expect($delegation->isActiveAt())->toBeTrue()
        ->and($delegation->ends_at)->not->toBeNull();
});

it('dispatches delegation events', function (): void {
    Event::fake();

    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();

    $manager->delegateApprovalsTo($assistant);
    $manager->revokeApprovalDelegation();

    Event::assertDispatched(ApprovalDelegated::class);
    Event::assertDispatched(ApprovalDelegationRevoked::class);
});

it('exposes the delegations relation and active scope', function (): void {
    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();

    $manager->delegateApprovalsTo($assistant);

    expect($manager->approvalDelegations)->toHaveCount(1)
        ->and(ApprovalDelegation::query()->active()->count())->toBe(1);
});

it('reports active state on the delegation model', function (): void {
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    $delegation = ApprovalDelegation::factory()->create([
        'ends_at' => CarbonImmutable::now()->subHour(),
    ]);

    expect($delegation->isActiveAt())->toBeFalse();

    $open = ApprovalDelegation::factory()->create();

    expect($open->isActiveAt())->toBeTrue();

    $revoked = ApprovalDelegation::factory()->revoked()->create();

    expect($revoked->isActiveAt())->toBeFalse();
});

it('counts a delegated decision toward a request as the delegator', function (): void {
    $manager = ReviewerTestModel::create();
    $assistant = ReviewerTestModel::create();
    $other = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $manager->delegateApprovalsTo($assistant);

    expect(ApprovalStatus::Pending)->toBe(ApprovalStatus::Pending);

    $assistant->approve($deployment);

    expect($deployment->approvalCount())->toBe(1);
});
