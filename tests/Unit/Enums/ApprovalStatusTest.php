<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

it('reports pending state', function (): void {
    expect(ApprovalStatus::Pending->isPending())->toBeTrue()
        ->and(ApprovalStatus::Approved->isPending())->toBeFalse();
});

it('reports decided state', function (): void {
    expect(ApprovalStatus::Approved->isDecided())->toBeTrue()
        ->and(ApprovalStatus::Rejected->isDecided())->toBeTrue()
        ->and(ApprovalStatus::Pending->isDecided())->toBeFalse()
        ->and(ApprovalStatus::Cancelled->isDecided())->toBeFalse()
        ->and(ApprovalStatus::Expired->isDecided())->toBeFalse();
});

it('reports final state', function (): void {
    expect(ApprovalStatus::Pending->isFinal())->toBeFalse()
        ->and(ApprovalStatus::Approved->isFinal())->toBeTrue()
        ->and(ApprovalStatus::Rejected->isFinal())->toBeTrue()
        ->and(ApprovalStatus::Cancelled->isFinal())->toBeTrue()
        ->and(ApprovalStatus::Expired->isFinal())->toBeTrue();
});

it('allows legal transitions from pending', function (ApprovalStatus $to): void {
    expect(ApprovalStatus::Pending->canTransitionTo($to))->toBeTrue();
})->with([
    ApprovalStatus::Approved,
    ApprovalStatus::Rejected,
    ApprovalStatus::Cancelled,
    ApprovalStatus::Expired,
]);

it('forbids transitioning pending to pending', function (): void {
    expect(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Pending))->toBeFalse();
});

it('allows an approved approval to be withdrawn', function (): void {
    expect(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Cancelled))->toBeTrue()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Rejected))->toBeFalse()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Expired))->toBeFalse()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Approved))->toBeFalse();
});

it('forbids any transition out of a terminal state', function (ApprovalStatus $from): void {
    foreach (ApprovalStatus::cases() as $to) {
        expect($from->canTransitionTo($to))->toBeFalse();
    }
})->with([
    ApprovalStatus::Rejected,
    ApprovalStatus::Cancelled,
    ApprovalStatus::Expired,
]);

it('exposes the expected string values', function (): void {
    expect(ApprovalStatus::Pending->value)->toBe('pending')
        ->and(ApprovalStatus::Approved->value)->toBe('approved')
        ->and(ApprovalStatus::Rejected->value)->toBe('rejected')
        ->and(ApprovalStatus::Cancelled->value)->toBe('cancelled')
        ->and(ApprovalStatus::Expired->value)->toBe('expired');
});
