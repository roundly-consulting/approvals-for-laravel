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
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Expired))->toBeTrue()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Rejected))->toBeFalse()
        ->and(ApprovalStatus::Approved->canTransitionTo(ApprovalStatus::Approved))->toBeFalse();
});

it('allows a rejection to be withdrawn or superseded, and nothing else', function (): void {
    expect(ApprovalStatus::Rejected->canTransitionTo(ApprovalStatus::Cancelled))->toBeTrue()
        ->and(ApprovalStatus::Rejected->canTransitionTo(ApprovalStatus::Approved))->toBeFalse()
        ->and(ApprovalStatus::Rejected->canTransitionTo(ApprovalStatus::Expired))->toBeFalse()
        ->and(ApprovalStatus::Rejected->canTransitionTo(ApprovalStatus::Rejected))->toBeFalse();
});

it('reports which statuses hold the live decision of a slot', function (): void {
    expect(ApprovalStatus::Pending->isLive())->toBeTrue()
        ->and(ApprovalStatus::Approved->isLive())->toBeTrue()
        ->and(ApprovalStatus::Rejected->isLive())->toBeTrue()
        ->and(ApprovalStatus::Cancelled->isLive())->toBeFalse()
        ->and(ApprovalStatus::Expired->isLive())->toBeFalse();
});

it('forbids any transition out of a terminal state', function (ApprovalStatus $from): void {
    foreach (ApprovalStatus::cases() as $to) {
        expect($from->canTransitionTo($to))->toBeFalse();
    }
})->with([
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

it('exposes the backed values in declaration order via the helpers trait', function (): void {
    expect(ApprovalStatus::values()->all())
        ->toBe(['pending', 'approved', 'rejected', 'cancelled', 'expired']);
});

it('builds headline labels and options', function (): void {
    expect(ApprovalStatus::labels()->all())
        ->toBe(['Pending', 'Approved', 'Rejected', 'Cancelled', 'Expired'])
        ->and(ApprovalStatus::toOptions()->all())->toBe([
            'pending' => 'Pending',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            'cancelled' => 'Cancelled',
            'expired' => 'Expired',
        ]);

    $options = ApprovalStatus::options();

    expect($options)->toHaveCount(5)
        ->and($options->first()->value)->toBe('pending')
        ->and($options->first()->label)->toBe('Pending')
        ->and($options->first()->name)->toBe('Pending');
});

it('builds a validation rule from the cases', function (): void {
    expect(ApprovalStatus::validationRule())
        ->toBe('in:pending,approved,rejected,cancelled,expired');
});

it('resolves cases by name and label', function (): void {
    expect(ApprovalStatus::tryFromName('Approved'))->toBe(ApprovalStatus::Approved)
        ->and(ApprovalStatus::tryFromName('nope'))->toBeNull()
        ->and(ApprovalStatus::tryFromLabel('Pending'))->toBe(ApprovalStatus::Pending);
});

it('exposes readable labels and comparison helpers', function (): void {
    expect(ApprovalStatus::Pending->readable())->toBe('Pending')
        ->and(ApprovalStatus::Approved->label())->toBe('Approved')
        ->and(ApprovalStatus::Approved->isIn([ApprovalStatus::Approved, ApprovalStatus::Rejected]))->toBeTrue()
        ->and(ApprovalStatus::Pending->isIn([ApprovalStatus::Approved, ApprovalStatus::Rejected]))->toBeFalse();
});

it('keeps its domain methods after adopting the helpers trait', function (): void {
    expect(ApprovalStatus::Pending->isFinal())->toBeFalse()
        ->and(ApprovalStatus::Approved->isDecided())->toBeTrue()
        ->and(ApprovalStatus::Pending->canTransitionTo(ApprovalStatus::Approved))->toBeTrue()
        ->and(ApprovalStatus::Cancelled->canTransitionTo(ApprovalStatus::Approved))->toBeFalse();
});
