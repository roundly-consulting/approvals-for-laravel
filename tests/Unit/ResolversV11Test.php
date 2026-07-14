<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestStageModelResolver;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;

// The toolkit's resolver validates only that the configured value is an Eloquent
// model; each package resolver narrows that to its own base class, and the
// failure keeps the package's documented exception.
it('rejects an eloquent model that is not an approval request', function (): void {
    config()->set('approvals.request_model', ActorTestModel::class);

    expect(fn () => ApprovalRequestModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});

it('rejects an eloquent model that is not a stage', function (): void {
    config()->set('approvals.stage_model', ActorTestModel::class);

    expect(fn () => ApprovalRequestStageModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});

it('rejects an eloquent model that is not a delegation', function (): void {
    config()->set('approvals.delegation_model', ActorTestModel::class);

    expect(fn () => ApprovalDelegationModelResolver::class())
        ->toThrow(InvalidApprovalModelException::class);
});

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
