<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

it('builds an approved decision', function (): void {
    $expires = CarbonImmutable::now()->addDay();

    $data = DecisionData::approved('looks good', $expires);

    expect($data->status)->toBe(ApprovalStatus::Approved)
        ->and($data->reason)->toBe('looks good')
        ->and($data->expiresAt)->toBe($expires);
});

it('builds a rejected decision', function (): void {
    $data = DecisionData::rejected('needs work');

    expect($data->status)->toBe(ApprovalStatus::Rejected)
        ->and($data->reason)->toBe('needs work')
        ->and($data->expiresAt)->toBeNull();
});

it('builds a pending decision', function (): void {
    $expires = CarbonImmutable::now()->addHour();

    $data = DecisionData::pending($expires);

    expect($data->status)->toBe(ApprovalStatus::Pending)
        ->and($data->expiresAt)->toBe($expires);
});

it('builds a cancelled decision', function (): void {
    $data = DecisionData::cancelled('withdrawn');

    expect($data->status)->toBe(ApprovalStatus::Cancelled)
        ->and($data->reason)->toBe('withdrawn');
});
