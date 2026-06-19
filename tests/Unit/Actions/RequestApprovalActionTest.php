<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\RequestApprovalAction;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequested;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('creates a pending approval', function (): void {
    Event::fake();

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(RequestApprovalAction::class)->execute(
        $actor,
        $deployment,
        DecisionData::pending(now()->addDay()),
    );

    expect($approval->status)->toBe(ApprovalStatus::Pending)
        ->and($approval->expires_at)->not->toBeNull();

    Event::assertDispatched(ApprovalRequested::class);
});

it('stores a reason on the pending approval', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(RequestApprovalAction::class)->execute(
        $actor,
        $deployment,
        new DecisionData(ApprovalStatus::Pending, reason: 'please review'),
    );

    expect($approval->reason)->toBe('please review');
});
