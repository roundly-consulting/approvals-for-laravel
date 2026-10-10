<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\ApprovalsException;
use RoundlyConsulting\Approvals\Exceptions\IncompletePendingApprovalException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
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

it('builds a defined-by-workflow message naming each refused setting', function (): void {
    $withApprovers = InvalidApprovalRequestException::definedByWorkflow('payout', ['from()', 'quorum()']);
    $rulesOnly = InvalidApprovalRequestException::definedByWorkflow('release', ['stages()', 'continueOnRejection()']);

    expect($withApprovers)->toBeInstanceOf(ApprovalsException::class)
        ->and($withApprovers->getMessage())->toBe(
            'The approval workflow preset [payout] defines the rule, quorum, stages and stage rejection itself '
            .'and takes its approvers in open(), so [from(), quorum()] cannot be set before workflow(). '
            .'Pass the approvers to open($approvers) instead of from().'
        )
        ->and($rulesOnly->getMessage())->toContain('[release]')
        ->and($rulesOnly->getMessage())->toContain('[stages(), continueOnRejection()]')
        ->and($rulesOnly->getMessage())->not->toContain('open($approvers)');
});

it('builds the messages for a setting ask() or close() has nowhere to keep', function (): void {
    $weight = InvalidApprovalRequestException::weightOnAsk();
    $reason = InvalidApprovalRequestException::reasonOnClose();

    expect($weight)->toBeInstanceOf(ApprovalsException::class)
        ->and($weight->getMessage())->toBe(
            'ask() records a pending decision, which counts towards no threshold, so weight() cannot be set before it. '
            .'Set weight() on the approve() or reject() that answers the ask.'
        )
        ->and($reason)->toBeInstanceOf(ApprovalsException::class)
        ->and($reason->getMessage())->toBe(
            'close() records no reason: an approval round has nowhere to keep one, so because() cannot be set before it. '
            .'Keep why the round was closed on your own model.'
        );
});

it('builds the message for an expiry set before reject()', function (): void {
    $e = InvalidApprovalRequestException::expiryOnReject();

    expect($e)->toBeInstanceOf(ApprovalsException::class)
        ->and($e->getMessage())->toBe(
            'reject() records a rejection, which has no expiry: it stands until it is withdrawn or superseded, '
            .'so expiresIn() / expiringAt() cannot be set before it. Withdraw the rejection with cancel() when it should stop counting.'
        );
});

it('builds the message naming each setting cancel() has no place for', function (): void {
    $e = InvalidApprovalRequestException::settingsOnCancel(['weight()', 'expiresIn() / expiringAt()']);

    expect($e)->toBeInstanceOf(ApprovalsException::class)
        ->and($e->getMessage())->toBe(
            'cancel() withdraws a decision and records none, so [weight(), expiresIn() / expiringAt()] cannot be set before it. '
            .'It takes because(), as the withdrawal\'s reason, and within().'
        );
});

it('builds the message naming each setting close() has no place for', function (): void {
    $withActor = InvalidApprovalRequestException::settingsOnClose(['as()', 'weight()']);
    $settingsOnly = InvalidApprovalRequestException::settingsOnClose(['expiresIn() / expiringAt()']);

    expect($withActor)->toBeInstanceOf(ApprovalsException::class)
        ->and($withActor->getMessage())->toBe(
            'close() closes a round from outside and records no decision, so [as(), weight()] cannot be set before it. '
            .'It takes for() and within() only. No actor is involved and no authorization gate runs: '
            .'check who may close the round before you call close().'
        )
        ->and($settingsOnly->getMessage())->toBe(
            'close() closes a round from outside and records no decision, so [expiresIn() / expiringAt()] cannot be set before it. '
            .'It takes for() and within() only.'
        );
});
