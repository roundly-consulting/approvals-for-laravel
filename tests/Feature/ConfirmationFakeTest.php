<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

beforeEach(function (): void {
    $this->fake = Approvals::fake();
    $this->subject = ReleaseTestModel::create();
    $this->other = ReleaseTestModel::create();
    $this->person = ReviewerTestModel::create();
    $this->stranger = ReviewerTestModel::create();
});

it('asserts nothing before any confirmation work', function (): void {
    $this->fake->assertNoConfirmationsRequested();
    $this->fake->assertNothingConfirmed();
    $this->fake->assertNothingDeclined();
    $this->fake->assertNoConfirmationsExpired();

    expect(fn () => $this->fake->assertConfirmationRequested($this->subject))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertConfirmed($this->subject))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertDeclined($this->subject))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertConfirmationsExpired())->toThrow(ExpectationFailedException::class, 'none did');
});

it('records a request, through the facade and an injected manager', function (): void {
    Approvals::confirmation($this->subject)->payload('h')->by($this->person)->request();
    app(ApprovalsManager::class)->confirmation($this->other)->payload('h')->by($this->person)->request();

    $this->fake->assertConfirmationRequested($this->subject);
    $this->fake->assertConfirmationRequested($this->subject, $this->person);
    $this->fake->assertConfirmationRequested($this->other, $this->person);

    expect(fn () => $this->fake->assertConfirmationRequested($this->subject, $this->stranger))
        ->toThrow(ExpectationFailedException::class, 'of the given person')
        ->and(fn () => $this->fake->assertConfirmationRequested(ReleaseTestModel::create()))
        ->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertNoConfirmationsRequested())
        ->toThrow(ExpectationFailedException::class, '2 were recorded')
        ->and($this->fake->recorded(ApprovalOperation::RequestConfirmation))->toHaveCount(2);
});

it('records a confirm, and never a refused one', function (): void {
    $confirmation = Approvals::confirmation($this->subject)->payload('h')->by($this->person)->request();

    expect(fn () => Approvals::confirm($confirmation, $this->stranger, 'h'))->toThrow(ConfirmationRefusedException::class)
        ->and(fn () => Approvals::confirm($confirmation, $this->person, 'other'))->toThrow(ConfirmationRefusedException::class);

    $this->fake->assertNothingConfirmed();

    Approvals::confirm($confirmation, $this->person, 'h');

    $this->fake->assertConfirmed($this->subject);
    $this->fake->assertConfirmed($this->subject, $this->person);
    $this->fake->assertNothingDeclined();

    expect(fn () => $this->fake->assertConfirmed($this->subject, $this->stranger))
        ->toThrow(ExpectationFailedException::class, 'by the given person')
        ->and(fn () => $this->fake->assertConfirmed($this->other))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertNothingConfirmed())->toThrow(ExpectationFailedException::class, '1 confirmation(s)');
});

it('records a decline', function (): void {
    $confirmation = Approvals::confirmation($this->subject)->payload('h')->by($this->person)->request();

    app(ApprovalsManager::class)->decline($confirmation, $this->person, 'no');

    $this->fake->assertDeclined($this->subject);
    $this->fake->assertDeclined($this->subject, $this->person);
    $this->fake->assertNothingConfirmed();

    expect(fn () => $this->fake->assertDeclined($this->subject, $this->stranger))
        ->toThrow(ExpectationFailedException::class, 'by the given person')
        ->and(fn () => $this->fake->assertDeclined($this->other))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertNothingDeclined())->toThrow(ExpectationFailedException::class, '1 decline(s)')
        ->and($this->fake->recorded(ApprovalOperation::Decline)[0]->context['reason'])->toBe('no');
});

it('records confirmation sweeps, from the facade and the console', function (): void {
    Approvals::confirmation($this->subject)->payload('h')->by($this->person)->expiresIn(1)->request();

    // A sweep that lapsed nothing still ran.
    Approvals::expireConfirmations();
    $this->fake->assertConfirmationsExpired(0);
    $this->fake->assertNoConfirmationsExpired();

    $this->artisan('approvals:expire-confirmations');
    Approvals::expireConfirmations(now()->addMinute());

    $this->fake->assertConfirmationsExpired();
    $this->fake->assertConfirmationsExpired(1);

    expect(fn () => $this->fake->assertConfirmationsExpired(2))->toThrow(ExpectationFailedException::class, 'Expected 2 confirmation(s)')
        ->and(fn () => $this->fake->assertNoConfirmationsExpired())->toThrow(ExpectationFailedException::class, 'but 1 were')
        ->and($this->fake->recorded(ApprovalOperation::ExpireConfirmations))->toHaveCount(3)
        // The approval-decision sweep assertions are untouched by confirmation sweeps.
        ->and(fn () => $this->fake->assertExpired())->toThrow(ExpectationFailedException::class);
});
