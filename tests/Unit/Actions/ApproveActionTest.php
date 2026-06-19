<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\RequestApprovalAction;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;

it('records an approval with a reason', function (): void {
    Event::fake();

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(ApproveAction::class)->execute(
        $actor,
        $deployment,
        DecisionData::approved('ship it'),
    );

    expect($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->reason)->toBe('ship it')
        ->and($approval->decided_at)->not->toBeNull();

    Event::assertDispatched(ApprovalApproved::class);
});

it('is idempotent when re-approving an active approval', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $first = app(ApproveAction::class)->execute($actor, $deployment);
    $second = app(ApproveAction::class)->execute($actor, $deployment);

    expect($second->id)->toBe($first->id)
        ->and(Approval::query()->count())->toBe(1);
});

it('approves a previously pending approval', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $pending = app(RequestApprovalAction::class)
        ->execute($actor, $deployment);

    $approved = app(ApproveAction::class)->execute($actor, $deployment);

    expect($approved->id)->toBe($pending->id)
        ->and($approved->status)->toBe(ApprovalStatus::Approved);
});

it('links a decision to an explicitly passed request', function (): void {
    $actor = ActorTestModel::create();
    $release = ReleaseTestModel::create();

    $request = $release->requestApproval([$actor], ApprovalRule::Any);

    $approval = app(ApproveAction::class)->execute($actor, $release, null, $request);

    expect($approval->approval_request_id)->toBe($request->id)
        ->and($request->refresh()->status)->toBe(ApprovalStatus::Approved);
});

it('applies an explicit expiry', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(ApproveAction::class)->execute(
        $actor,
        $deployment,
        DecisionData::approved(expiresAt: now()->addDay()),
    );

    expect($approval->expires_at)->not->toBeNull();
});
