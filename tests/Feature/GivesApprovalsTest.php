<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('toggles approval for given model', function (): void {
    Event::fake();

    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();

    expect($actor->hasApproved($deployment))->toBeFalse();

    $approved = $actor->toggleApproval($deployment);

    expect($approved)->toBeTrue();

    Event::assertDispatched(fn (ApprovalToggled $event): bool => $event->actor === $actor
        && $event->entity === $deployment
        && $event->hasBeenApproved === true);

    $this->assertDatabaseHas('approvals', [
        'actor_id' => $actor->id,
        'actor_type' => $actor->getMorphClass(),
        'approvable_id' => $deployment->id,
        'approvable_type' => $deployment->getMorphClass(),
        'deleted_at' => null,
    ]);

    $approved = $actor->toggleApproval($deployment);

    expect($approved)->toBeFalse()
        ->and($actor->hasApproved($deployment))->toBeFalse();

    Event::assertDispatched(fn (ApprovalToggled $event): bool => $event->actor === $actor
        && $event->entity === $deployment
        && $event->hasBeenApproved === false);

    // The row is soft-deleted, not removed, so it no longer counts as an active approval.
    $this->assertSoftDeleted('approvals', [
        'actor_id' => $actor->id,
        'actor_type' => $actor->getMorphClass(),
        'approvable_id' => $deployment->id,
        'approvable_type' => $deployment->getMorphClass(),
    ]);
});

it('re-approves after a prior approval was removed', function (): void {
    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();

    expect($actor->toggleApproval($deployment))->toBeTrue()
        ->and($actor->toggleApproval($deployment))->toBeFalse()
        ->and($actor->toggleApproval($deployment))->toBeTrue()
        ->and($actor->hasApproved($deployment))->toBeTrue();
});

it('lists approvals given by an actor', function (): void {
    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();

    $actor->toggleApproval($deployment);

    expect($actor->approvals)
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(1)
        ->and($actor->approvals->first())
        ->toBeInstanceOf(Approval::class);
});

it('checks whether an actor has approved an entity', function (): void {
    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();
    $anotherActor = ActorTestModel::create();

    $actor->toggleApproval($deployment);

    expect($actor->hasApproved($deployment))
        ->toBeTrue()
        ->and($anotherActor->hasApproved($deployment))
        ->toBeFalse();
});
