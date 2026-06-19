<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ToggleApprovalAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
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
