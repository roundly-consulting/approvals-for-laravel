<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ToggleApprovalAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('toggles an approval on and off', function (): void {
    Event::fake();

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    expect(app(ToggleApprovalAction::class)->execute($actor, $deployment))->toBeTrue();

    $this->assertDatabaseHas('approvals', [
        'actor_id' => $actor->id,
        'approvable_id' => $deployment->id,
        'status' => ApprovalStatus::Approved->value,
        'deleted_at' => null,
    ]);

    expect(app(ToggleApprovalAction::class)->execute($actor, $deployment))->toBeFalse();

    $this->assertSoftDeleted('approvals', [
        'actor_id' => $actor->id,
        'approvable_id' => $deployment->id,
    ]);

    Event::assertDispatched(ApprovalToggled::class, 2);
});

it('creates an approved row on toggle', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    app(ToggleApprovalAction::class)->execute($actor, $deployment);

    expect(Approval::query()->first()->status)->toBe(ApprovalStatus::Approved);
});

it('dispatches ApprovalCancelled on toggle-off, like a withdrawal', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    app(ToggleApprovalAction::class)->execute($actor, $deployment);

    Event::fake([ApprovalCancelled::class, ApprovalStatusChanged::class]);

    app(ToggleApprovalAction::class)->execute($actor, $deployment);

    Event::assertDispatchedTimes(ApprovalCancelled::class, 1);
    Event::assertDispatched(
        ApprovalCancelled::class,
        fn (ApprovalCancelled $event): bool => $event->approval->status === ApprovalStatus::Cancelled
            && $event->approval->actor_id === $actor->getKey(),
    );
    Event::assertDispatched(
        ApprovalStatusChanged::class,
        fn (ApprovalStatusChanged $event): bool => $event->from === ApprovalStatus::Approved && $event->to === ApprovalStatus::Cancelled,
    );
});

it('does not dispatch ApprovalCancelled on toggle-on', function (): void {
    Event::fake([ApprovalCancelled::class]);

    app(ToggleApprovalAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create());

    Event::assertNotDispatched(ApprovalCancelled::class);
});
