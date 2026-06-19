<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestStageModelResolver;

it('resolves the default stage model', function (): void {
    expect(ApprovalRequestStageModelResolver::class())->toBe(ApprovalRequestStage::class);
});

it('rejects an invalid stage model', function (): void {
    config()->set('approvals.stage_model', stdClass::class);

    expect(fn () => ApprovalRequestStageModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});

it('rejects a non-string stage model', function (): void {
    config()->set('approvals.stage_model', 123);

    expect(fn () => ApprovalRequestStageModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});

it('resolves the default delegation model', function (): void {
    expect(ApprovalDelegationModelResolver::class())->toBe(ApprovalDelegation::class);
});

it('rejects an invalid delegation model', function (): void {
    config()->set('approvals.delegation_model', stdClass::class);

    expect(fn () => ApprovalDelegationModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});

it('rejects a non-string delegation model', function (): void {
    config()->set('approvals.delegation_model', 123);

    expect(fn () => ApprovalDelegationModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});
