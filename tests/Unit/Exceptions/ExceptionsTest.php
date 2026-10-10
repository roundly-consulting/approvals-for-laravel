<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\ApprovalsException;
use RoundlyConsulting\Approvals\Exceptions\IncompletePendingApprovalException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;

it('builds an invalid model exception message', function (): void {
    $e = InvalidApprovalModelException::forClass('Foo', Approval::class);

    expect($e)->toBeInstanceOf(ApprovalsException::class)
        ->and($e->getMessage())->toContain('Foo')
        ->and($e->getMessage())->toContain(Approval::class);
});

it('builds an invalid transition exception message', function (): void {
    $e = InvalidStatusTransitionException::between(ApprovalStatus::Approved, ApprovalStatus::Pending);

    expect($e)->toBeInstanceOf(ApprovalsException::class)
        ->and($e->getMessage())->toContain('approved')
        ->and($e->getMessage())->toContain('pending');
});

it('builds a closing-outcome exception message', function (): void {
    $e = InvalidStatusTransitionException::notAClosingOutcome(ApprovalStatus::Approved);

    expect($e)->toBeInstanceOf(ApprovalsException::class)
        ->and($e->getMessage())->toContain('[approved]')
        ->and($e->getMessage())->toContain('[cancelled]')
        ->and($e->getMessage())->toContain('[expired]');
});

it('builds an unauthorized exception message', function (): void {
    $actor = ActorTestModel::create();

    $e = UnauthorizedApprovalException::forActor($actor);

    expect($e)->toBeInstanceOf(ApprovalsException::class)
        ->and($e->getMessage())->toContain(ActorTestModel::class);
});

it('builds incomplete pending approval messages', function (): void {
    expect(IncompletePendingApprovalException::missingActor()->getMessage())->toContain('actor')
        ->and(IncompletePendingApprovalException::missingApprovable()->getMessage())->toContain('approvable');
});
