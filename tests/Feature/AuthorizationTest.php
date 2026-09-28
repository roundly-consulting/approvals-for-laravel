<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\RejectAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
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

it('enforces the gate for every truthy env string', function (string $env): void {
    config()->set('approvals.authorization.enabled', $env);
    Gate::define('decide-approval', fn (): bool => false);

    expect(fn () => app(ApproveAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create()))
        ->toThrow(UnauthorizedApprovalException::class);
})->with(['1', 'true', 'yes', 'on', 'TRUE']);

it('stays off for falsy env strings', function (string $env): void {
    config()->set('approvals.authorization.enabled', $env);
    Gate::define('decide-approval', fn (): bool => false);

    expect(app(ApproveAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create())->status)
        ->toBe(ApprovalStatus::Approved);
})->with(['0', 'false', 'no', 'off', '']);

it('gates every decision path, not only approve and reject', function (string $verb): void {
    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();

    // An earlier decision (made while the gate was off) gives cancel() something to withdraw.
    Approvals::for($deployment)->as($actor)->approve();

    config()->set('approvals.authorization.enabled', true);
    Gate::define('decide-approval', fn (): bool => false);

    $before = Approval::query()->withTrashed()->get()->map->only(['id', 'status', 'deleted_at'])->all();

    expect(fn () => Approvals::for($deployment)->as($actor)->{$verb}())
        ->toThrow(UnauthorizedApprovalException::class);

    expect(Approval::query()->withTrashed()->get()->map->only(['id', 'status', 'deleted_at'])->all())->toBe($before);
})->with(['toggle', 'ask', 'cancel']);

it('lets a gated toggle through when the gate allows', function (): void {
    config()->set('approvals.authorization.enabled', true);
    Gate::define('decide-approval', fn (): bool => true);

    $deployment = DeploymentTestModel::create();
    $actor = ActorTestModel::create();

    expect(Approvals::for($deployment)->as($actor)->toggle())->toBeTrue()
        ->and($deployment->hasBeenApprovedBy($actor))->toBeTrue();
});
