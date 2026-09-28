<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\TeamTestModel;

it('lets one model both give and receive approvals', function (): void {
    $platform = TeamTestModel::create();
    $security = TeamTestModel::create();
    $release = ReleaseTestModel::create();

    $platform->approve($release);
    $security->approve($platform);

    expect($platform->givenApprovals()->count())->toBe(1)
        ->and($platform->approvals()->count())->toBe(1)
        ->and($platform->hasApproved($release))->toBeTrue()
        ->and($platform->hasBeenApprovedBy($security))->toBeTrue()
        ->and($security->hasApproved($platform))->toBeTrue()
        ->and($security->approvals()->count())->toBe(0)
        ->and($platform->approvalFor($release)?->status)->toBe(ApprovalStatus::Approved);
});

it('opens and resolves a request on a model that also decides', function (): void {
    $team = TeamTestModel::create();
    $lead = TeamTestModel::create();

    $team->requestApproval([$lead]);
    $lead->approve($team);

    expect($team->isApproved())->toBeTrue();
});
