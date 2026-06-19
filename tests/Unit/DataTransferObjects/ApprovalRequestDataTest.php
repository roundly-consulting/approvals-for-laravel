<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;

it('holds request configuration', function (): void {
    $subject = ReleaseTestModel::create();
    $a = ActorTestModel::create();
    $b = ActorTestModel::create();

    $data = new ApprovalRequestData(
        subject: $subject,
        approvers: [$a, $b],
        rule: ApprovalRule::Quorum,
        quorum: 2,
    );

    expect($data->subject)->toBe($subject)
        ->and($data->approvers)->toHaveCount(2)
        ->and($data->rule)->toBe(ApprovalRule::Quorum)
        ->and($data->quorum)->toBe(2)
        ->and($data->expiresAt)->toBeNull();
});

it('defaults to unanimous with no quorum', function (): void {
    $subject = ReleaseTestModel::create();

    $data = new ApprovalRequestData(subject: $subject, approvers: []);

    expect($data->rule)->toBe(ApprovalRule::Unanimous)
        ->and($data->quorum)->toBeNull();
});
