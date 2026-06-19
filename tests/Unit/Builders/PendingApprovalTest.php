<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\IncompletePendingApprovalException;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('approves with a reason via the builder', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = (new PendingApproval)
        ->for($deployment)
        ->as($actor)
        ->because('looks good to me')
        ->approve();

    expect($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->reason)->toBe('looks good to me');
});

it('rejects via the builder', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = (new PendingApproval)->as($actor)->for($deployment)->because('no')->reject();

    expect($approval->status)->toBe(ApprovalStatus::Rejected);
});

it('requests via the builder', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = (new PendingApproval)->as($actor)->for($deployment)->request();

    expect($approval->status)->toBe(ApprovalStatus::Pending);
});

it('cancels via the builder', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    (new PendingApproval)->as($actor)->for($deployment)->approve();
    $cancelled = (new PendingApproval)->as($actor)->for($deployment)->cancel();

    expect($cancelled)->not->toBeNull()
        ->and($cancelled->status)->toBe(ApprovalStatus::Cancelled);
});

it('toggles via the builder', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    expect((new PendingApproval)->as($actor)->for($deployment)->toggle())->toBeTrue();
});

it('reports approval status via the builder', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    expect((new PendingApproval)->as($actor)->for($deployment)->isApproved())->toBeFalse();

    (new PendingApproval)->as($actor)->for($deployment)->approve();

    expect((new PendingApproval)->as($actor)->for($deployment)->isApproved())->toBeTrue();
});

it('applies expiry via expiresIn', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = (new PendingApproval)->as($actor)->for($deployment)->expiresIn(3600)->approve();

    expect($approval->expires_at)->not->toBeNull();
});

it('applies expiry via expiringAt', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = (new PendingApproval)->as($actor)->for($deployment)->expiringAt(now()->addWeek())->approve();

    expect($approval->expires_at)->not->toBeNull();
});

it('fails when actor is missing', function (): void {
    $deployment = DeploymentTestModel::create();

    (new PendingApproval)->for($deployment)->approve();
})->throws(IncompletePendingApprovalException::class);

it('fails when approvable is missing', function (): void {
    $actor = ActorTestModel::create();

    (new PendingApproval)->as($actor)->approve();
})->throws(IncompletePendingApprovalException::class);
