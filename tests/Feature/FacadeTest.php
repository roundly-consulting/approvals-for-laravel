<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\ApprovalManager;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;

it('resolves the manager singleton', function (): void {
    expect(app(ApprovalManager::class))->toBe(app(ApprovalManager::class));
});

it('approves through the facade', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    $approval = Approvals::for($deployment)->as($actor)->because('LGTM')->approve();

    expect($approval->status)->toBe(ApprovalStatus::Approved)
        ->and($approval->reason)->toBe('LGTM');
});

it('starts a builder via as()', function (): void {
    $actor = ActorTestModel::create();

    expect(Approvals::as($actor))->toBeInstanceOf(PendingApproval::class);
});

it('expires through the facade', function (): void {
    $actor = ActorTestModel::create();
    $deployment = DeploymentTestModel::create();

    Approvals::for($deployment)->as($actor)->request();
    Approval::query()->update(['expires_at' => now()->subDay()]);

    expect(Approvals::expire())->toBe(1);
});

it('exposes the approvals helper', function (): void {
    expect(approvals())->toBeInstanceOf(ApprovalManager::class);
});

it('registers the Approvals alias', function (): void {
    expect(class_exists('Approvals'))->toBeTrue();
});
