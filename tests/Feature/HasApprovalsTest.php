<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use RoundlyConsulting\Approvals\Facades\Approvals;
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

it('exposes approvable-side helpers', function (): void {
    $deployment = DeploymentTestModel::create();
    $a = ActorTestModel::create();
    $b = ActorTestModel::create();

    $a->approve($deployment);
    $b->reject($deployment);

    expect($deployment->isApprovedBy($a))->toBeTrue()
        ->and($deployment->hasBeenRejectedBy($b))->toBeTrue()
        ->and($deployment->approvalCount())->toBe(1);
});

it('lists pending approvals for an entity', function (): void {
    $deployment = DeploymentTestModel::create();
    $a = ActorTestModel::create();

    Approvals::for($deployment)->as($a)->request();

    expect($deployment->pendingApprovals())->toHaveCount(1);
});
