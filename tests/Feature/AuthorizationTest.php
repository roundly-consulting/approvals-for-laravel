<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\RejectAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('passes through when authorization is disabled', function (): void {
    config()->set('approvals.authorization.enabled', false);

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(ApproveAction::class)->execute($actor, $deployment);

    expect($approval->status)->toBe(ApprovalStatus::Approved);
});

it('records a decision when the gate allows', function (): void {
    config()->set('approvals.authorization.enabled', true);
    Gate::define('decide-approval', fn (): bool => true);

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = app(ApproveAction::class)->execute($actor, $deployment);

    expect($approval->status)->toBe(ApprovalStatus::Approved);
});

it('throws when the gate denies', function (): void {
    config()->set('approvals.authorization.enabled', true);
    Gate::define('decide-approval', fn (): bool => false);

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    app(ApproveAction::class)->execute($actor, $deployment);
})->throws(UnauthorizedApprovalException::class);

it('honours a custom ability name', function (): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.authorization.ability', 'sign-off');
    Gate::define('sign-off', fn (): bool => false);

    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    app(RejectAction::class)->execute($actor, $deployment);
})->throws(UnauthorizedApprovalException::class);
