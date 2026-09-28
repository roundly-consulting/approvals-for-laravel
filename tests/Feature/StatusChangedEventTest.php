<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequested;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

it('fires the umbrella event alongside an approval', function (): void {
    Event::fake();

    $user = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $user->approve($deployment);

    Event::assertDispatched(ApprovalStatusChanged::class, function (ApprovalStatusChanged $event) use ($deployment): bool {
        return $event->to === ApprovalStatus::Approved
            && $event->from === ApprovalStatus::Pending
            && $event->subject->approvable_id === $deployment->getKey();
    });
});

it('fires the umbrella event on rejection', function (): void {
    Event::fake();

    $user = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $user->reject($deployment);

    Event::assertDispatched(
        ApprovalStatusChanged::class,
        fn (ApprovalStatusChanged $event): bool => $event->to === ApprovalStatus::Rejected,
    );
});

it('fires the umbrella event on cancellation', function (): void {
    $user = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $user->approve($deployment);

    Event::fake();

    $user->cancelApproval($deployment);

    Event::assertDispatched(
        ApprovalStatusChanged::class,
        fn (ApprovalStatusChanged $event): bool => $event->to === ApprovalStatus::Cancelled,
    );
});

it('fires the umbrella event when an approval expires', function (): void {
    $user = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    Approvals::for($deployment)->as($user)->expiresIn(1)->ask();

    Event::fake();

    Approvals::expire(now()->addMinute());

    Event::assertDispatched(
        ApprovalStatusChanged::class,
        fn (ApprovalStatusChanged $event): bool => $event->to === ApprovalStatus::Expired,
    );
});

it('fires the umbrella event when a toggle approves and when it withdraws', function (): void {
    $user = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    Event::fake([ApprovalStatusChanged::class]);

    $user->toggleApproval($deployment);
    $user->toggleApproval($deployment);

    Event::assertDispatched(ApprovalStatusChanged::class, fn (ApprovalStatusChanged $event): bool => $event->from === ApprovalStatus::Pending
        && $event->to === ApprovalStatus::Approved);
    Event::assertDispatched(ApprovalStatusChanged::class, fn (ApprovalStatusChanged $event): bool => $event->from === ApprovalStatus::Approved
        && $event->to === ApprovalStatus::Cancelled);
});

it('fires the umbrella event for the decision a change of mind retires', function (): void {
    $user = ReviewerTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = $user->approve($deployment);

    Event::fake([ApprovalStatusChanged::class]);

    $user->reject($deployment);

    Event::assertDispatched(ApprovalStatusChanged::class, fn (ApprovalStatusChanged $event): bool => $event->subject->is($approval)
        && $event->from === ApprovalStatus::Approved
        && $event->to === ApprovalStatus::Cancelled);
});

it('does not treat an ask as a status change', function (): void {
    Event::fake([ApprovalStatusChanged::class, ApprovalRequested::class]);

    Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())->ask();

    Event::assertDispatched(ApprovalRequested::class);
    Event::assertNotDispatched(ApprovalStatusChanged::class);
});
