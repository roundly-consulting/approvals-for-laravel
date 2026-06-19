<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\CancelApprovalAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('cancels an active approval', function (): void {
    Event::fake();

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    app(ApproveAction::class)->execute($actor, $deployment);

    $cancelled = app(CancelApprovalAction::class)->execute($actor, $deployment, 'withdrawn');

    expect($cancelled)->not->toBeNull()
        ->and($cancelled->status)->toBe(ApprovalStatus::Cancelled)
        ->and($cancelled->reason)->toBe('withdrawn');

    Event::assertDispatched(ApprovalCancelled::class);
});

it('returns null when there is nothing to cancel', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    expect(app(CancelApprovalAction::class)->execute($actor, $deployment))->toBeNull();
});
