<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('lists approvals for a given entity', function (): void {
    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();

    $actor->toggleApproval($deployment);

    expect($deployment->approvals)
        ->toBeInstanceOf(Collection::class)
        ->toHaveCount(1)
        ->and($deployment->approvals->first())
        ->toBeInstanceOf(Approval::class)
        ->actor->toBeInstanceOf(ActorTestModel::class)
        ->actor->id->toBe($actor->id);
});

it('checks whether an entity has been approved by an actor', function (): void {
    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();
    $anotherActor = ActorTestModel::create();

    $actor->toggleApproval($deployment);

    expect($deployment->hasBeenApprovedBy($actor))
        ->toBeTrue()
        ->and($deployment->hasBeenApprovedBy($anotherActor))
        ->toBeFalse();
});
