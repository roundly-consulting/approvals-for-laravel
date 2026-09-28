<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\RejectAction;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRejected;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;

it('records a rejection with a reason', function (): void {
    Event::fake();

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(RejectAction::class)->execute(
        $actor,
        $deployment,
        DecisionData::rejected('needs tests'),
    );

    expect($approval->status)->toBe(ApprovalStatus::Rejected)
        ->and($approval->reason)->toBe('needs tests');

    Event::assertDispatched(ApprovalRejected::class);
});

it('links a fresh rejection over a prior approval to a request', function (): void {
    $actor = ActorTestModel::create();
    $release = ReleaseTestModel::create();

    $request = $release->requestApproval(
        [$actor],
        ApprovalRule::Any,
    );

    app(ApproveAction::class)->execute($actor, $release, null, $request);

    // Resolve the request so the next decision builds a fresh row tied to it.
    $request->status = ApprovalStatus::Pending;
    $request->resolved_at = null;
    $request->save();

    $rejected = app(RejectAction::class)->execute($actor, $release, null, $request);

    expect($rejected->approval_request_id)->toBe($request->id)
        ->and($rejected->status)->toBe(ApprovalStatus::Rejected);
});

it('records a fresh rejection over a prior approval and withdraws the approval', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approved = app(ApproveAction::class)->execute($actor, $deployment);
    $rejected = app(RejectAction::class)->execute($actor, $deployment);

    expect($rejected->id)->not->toBe($approved->id)
        ->and($rejected->status)->toBe(ApprovalStatus::Rejected)
        ->and($approved->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
        ->and(Approval::query()->approved()->count())->toBe(0)
        ->and(Approval::query()->rejected()->count())->toBe(1);
});
