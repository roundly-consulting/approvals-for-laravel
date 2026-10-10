<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Testing\ApprovalsFake;
use RoundlyConsulting\Approvals\Testing\InteractsWithApprovals;
use RoundlyConsulting\Approvals\Testing\RecordedApprovalOperation;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

uses(InteractsWithApprovals::class);

it('swaps a manager subtype in for the facade and for injection', function (): void {
    $fake = Approvals::fake();

    expect($fake)->toBeInstanceOf(ApprovalsFake::class)
        ->toBeInstanceOf(ApprovalsManager::class)
        ->and(app(ApprovalsManager::class))->toBe($fake)
        ->and(approvals())->toBe($fake);
});

it('still performs every operation', function (): void {
    Approvals::fake();

    $release = ReleaseTestModel::create();
    $reviewer = ReviewerTestModel::create();

    $request = Approvals::request($release)->from([$reviewer])->open();
    $approval = Approvals::for($release)->as($reviewer)->approve();

    expect($request->exists)->toBeTrue()
        ->and($approval->exists)->toBeTrue()
        ->and(Approvals::status($release))->toBe(ApprovalStatus::Approved);
});

it('records calls made through the model traits, an injected manager and the test helpers', function (): void {
    $fake = Approvals::fake();

    $release = ReleaseTestModel::create();
    [$a, $b, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

    $release->requestApproval([$a, $b]);
    $a->approve($release);
    app(ApprovalsManager::class)->for($release)->as($b)->reject();
    // The round above is closed (b rejected it): the helper decides on another subject.
    $this->approveAs(ReleaseTestModel::create(), actor: $c);

    expect(array_map(
        static fn (RecordedApprovalOperation $recorded): ApprovalOperation => $recorded->operation,
        $fake->recorded(),
    ))->toBe([ApprovalOperation::Open, ApprovalOperation::Approve, ApprovalOperation::Reject, ApprovalOperation::Approve])
        ->and($fake->recorded(ApprovalOperation::Approve))->toHaveCount(2)
        ->and($fake->recorded(ApprovalOperation::Approve)[0]->result)->toBeInstanceOf(Approval::class)
        ->and($fake->recorded(ApprovalOperation::Approve)[0]->model('actor')?->is($a))->toBeTrue()
        ->and($fake->recorded(ApprovalOperation::Approve)[0]->model('reason'))->toBeNull();
});

it('closes a round exactly as the real manager does, and records it', function (): void {
    $fake = Approvals::fake();

    $release = ReleaseTestModel::create();
    [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    $request = Approvals::request($release)->from([$alice, $bob])->open();
    $ask = Approvals::for($release)->as($alice)->ask();

    Event::fake([ApprovalCancelled::class, ApprovalRequestResolved::class, ApprovalStatusChanged::class]);

    expect(Approvals::for($release)->within($request)->close(ApprovalStatus::Expired))->toBe(1)
        ->and(app(ApprovalsManager::class)->for($release)->close())->toBe(0)
        ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired)
        ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
        ->and($ask->fresh()?->live)->toBeNull()
        ->and(Approvals::for($release)->hasPending())->toBeFalse()
        ->and(fn () => $alice->approve($release))->toThrow(ClosedApprovalRequestException::class);

    Event::assertDispatchedTimes(ApprovalCancelled::class, 1);
    Event::assertDispatchedTimes(ApprovalRequestResolved::class, 1);
    Event::assertDispatchedTimes(ApprovalStatusChanged::class, 2);

    $closes = $fake->recorded(ApprovalOperation::Close);

    expect($closes)->toHaveCount(2)
        ->and($closes[0]->model('subject')?->is($release))->toBeTrue()
        ->and($closes[0]->model('request')?->is($request))->toBeTrue()
        ->and($closes[0]->context['outcome'])->toBe(ApprovalStatus::Expired)
        ->and($closes[0]->result)->toBe(1)
        ->and($closes[1]->model('request'))->toBeNull()
        ->and($closes[1]->context['outcome'])->toBe(ApprovalStatus::Cancelled)
        ->and($closes[1]->result)->toBe(0);
});

it('does not record a close it refused', function (): void {
    $fake = Approvals::fake();

    expect(fn () => Approvals::for(ReleaseTestModel::create())->close(ApprovalStatus::Approved))
        ->toThrow(InvalidStatusTransitionException::class);

    $fake->assertNothingClosed();
});

it('does not record an operation that failed', function (): void {
    $fake = Approvals::fake();
    $boss = ReviewerTestModel::create();

    expect(fn () => $boss->delegateApprovalsTo($boss))->toThrow(Exception::class);

    $fake->assertNothingDelegated();
});

describe('assertions', function (): void {
    beforeEach(function (): void {
        $this->fake = Approvals::fake();
        $this->release = ReleaseTestModel::create();
        $this->other = ReleaseTestModel::create();
        $this->reviewer = ReviewerTestModel::create();
        $this->stranger = ReviewerTestModel::create();
    });

    it('assertApproved / assertNothingApproved', function (): void {
        $this->fake->assertNothingApproved();
        expect(fn () => $this->fake->assertApproved($this->release))->toThrow(ExpectationFailedException::class);

        $this->reviewer->approve($this->release);

        $this->fake->assertApproved($this->release);
        $this->fake->assertApproved($this->release, $this->reviewer);
        expect(fn () => $this->fake->assertApproved($this->release, $this->stranger))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertApproved($this->other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingApproved())->toThrow(ExpectationFailedException::class, '1 approval(s)');
    });

    it('assertRejected / assertNothingRejected', function (): void {
        $this->fake->assertNothingRejected();
        expect(fn () => $this->fake->assertRejected($this->release))->toThrow(ExpectationFailedException::class);

        $this->reviewer->reject($this->release, 'no');

        $this->fake->assertRejected($this->release, $this->reviewer);
        expect(fn () => $this->fake->assertRejected($this->release, $this->stranger))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingRejected())->toThrow(ExpectationFailedException::class);
    });

    it('assertAsked / assertNothingAsked', function (): void {
        $this->fake->assertNothingAsked();
        expect(fn () => $this->fake->assertAsked($this->release))->toThrow(ExpectationFailedException::class);

        Approvals::for($this->release)->as($this->reviewer)->ask();

        $this->fake->assertAsked($this->release, $this->reviewer);
        expect(fn () => $this->fake->assertAsked($this->other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingAsked())->toThrow(ExpectationFailedException::class);
    });

    it('assertCancelled / assertNothingCancelled', function (): void {
        $this->reviewer->approve($this->release);

        $this->fake->assertNothingCancelled();
        expect(fn () => $this->fake->assertCancelled($this->release))->toThrow(ExpectationFailedException::class);

        $this->reviewer->cancelApproval($this->release);

        $this->fake->assertCancelled($this->release, $this->reviewer);
        expect(fn () => $this->fake->assertCancelled($this->release, $this->stranger))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingCancelled())->toThrow(ExpectationFailedException::class);
    });

    it('assertToggled / assertNothingToggled', function (): void {
        $this->fake->assertNothingToggled();
        expect(fn () => $this->fake->assertToggled($this->release))->toThrow(ExpectationFailedException::class);

        $this->reviewer->toggleApproval($this->release);

        $this->fake->assertToggled($this->release, $this->reviewer);
        expect(fn () => $this->fake->assertToggled($this->other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingToggled())->toThrow(ExpectationFailedException::class);
    });

    it('assertOpened / assertNothingOpened', function (): void {
        config()->set('approvals.workflows.payout', ['rule' => 'any']);

        $this->fake->assertNothingOpened();
        expect(fn () => $this->fake->assertOpened($this->release))->toThrow(ExpectationFailedException::class);

        $this->release->requestStagedApproval([new StageDefinition([$this->reviewer])]);

        $this->fake->assertOpened($this->release);
        expect(fn () => $this->fake->assertOpened($this->release, 'payout'))->toThrow(ExpectationFailedException::class, 'payout')
            ->and(fn () => $this->fake->assertOpened($this->other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingOpened())->toThrow(ExpectationFailedException::class);

        Approvals::request($this->release)->workflow('payout')->open([$this->reviewer]);

        $this->fake->assertOpened($this->release, 'payout');
    });

    it('assertDelegated / assertNothingDelegated', function (): void {
        $this->fake->assertNothingDelegated();
        expect(fn () => $this->fake->assertDelegated($this->reviewer))->toThrow(ExpectationFailedException::class);

        $this->reviewer->delegateApprovalsTo($this->stranger);

        $this->fake->assertDelegated($this->reviewer);
        $this->fake->assertDelegated($this->reviewer, $this->stranger);
        expect(fn () => $this->fake->assertDelegated($this->stranger))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertDelegated($this->reviewer, $this->reviewer))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingDelegated())->toThrow(ExpectationFailedException::class);
    });

    it('assertRevoked / assertNothingRevoked', function (): void {
        Approvals::delegations($this->reviewer)->to($this->stranger)->grant();

        $this->fake->assertNothingRevoked();
        expect(fn () => $this->fake->assertRevoked($this->reviewer))->toThrow(ExpectationFailedException::class);

        $this->reviewer->revokeApprovalDelegation($this->stranger);

        $this->fake->assertRevoked($this->reviewer);
        $this->fake->assertRevoked($this->reviewer, $this->stranger);
        expect(fn () => $this->fake->assertRevoked($this->stranger))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingRevoked())->toThrow(ExpectationFailedException::class);
    });

    it('assertClosed / assertNothingClosed', function (): void {
        Approvals::request($this->release)->from([$this->reviewer])->open();

        $this->fake->assertNothingClosed();
        expect(fn () => $this->fake->assertClosed($this->release))
            ->toThrow(ExpectationFailedException::class, 'Expected an approval round of the subject to be closed');

        Approvals::for($this->release)->close();

        $this->fake->assertClosed($this->release);
        $this->fake->assertClosed($this->release, ApprovalStatus::Cancelled);
        expect(fn () => $this->fake->assertClosed($this->release, ApprovalStatus::Expired))
            ->toThrow(ExpectationFailedException::class, 'closed as [expired]')
            ->and(fn () => $this->fake->assertClosed($this->other))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingClosed())->toThrow(ExpectationFailedException::class, '1 close(s)');

        Approvals::assertClosed($this->release, ApprovalStatus::Cancelled);
    });

    it('assertExpired / assertNothingExpired', function (): void {
        expect(fn () => $this->fake->assertExpired())->toThrow(ExpectationFailedException::class, 'none did');

        Approvals::for($this->release)->as($this->reviewer)->expiresIn(-60)->ask();
        Approvals::expire();

        $this->fake->assertExpired();
        $this->fake->assertExpired(1);
        expect(fn () => $this->fake->assertExpired(2))->toThrow(ExpectationFailedException::class)
            ->and(fn () => $this->fake->assertNothingExpired())->toThrow(ExpectationFailedException::class, '1 decision(s)');
    });

    it('assertExpired narrowed to the sweeps of one subject type', function (): void {
        Approvals::for($this->release)->as($this->reviewer)->expiresIn(-60)->ask();
        Approvals::expire(subjectType: ReleaseTestModel::class);

        $this->fake->assertExpired(subjectType: ReleaseTestModel::class);
        $this->fake->assertExpired(1, ReleaseTestModel::class);
        expect(fn () => $this->fake->assertExpired(subjectType: ReviewerTestModel::class))
            ->toThrow(ExpectationFailedException::class, 'of ['.ReviewerTestModel::class.']')
            ->and(fn () => $this->fake->assertExpired(2, ReleaseTestModel::class))->toThrow(ExpectationFailedException::class);
    });

    it('assertExpired with a type ignores app-wide sweeps', function (): void {
        Approvals::expire();

        expect(fn () => $this->fake->assertExpired(subjectType: ReleaseTestModel::class))
            ->toThrow(ExpectationFailedException::class, 'none did');
    });

    it('assertNothingExpired passes when a sweep lapsed nothing', function (): void {
        Approvals::expire();

        $this->fake->assertExpired(0);
        $this->fake->assertNothingExpired();
    });

    it('is reachable statically through the facade', function (): void {
        $this->reviewer->approve($this->release);

        Approvals::assertApproved($this->release, $this->reviewer);
        Approvals::assertNothingRejected();

        expect(Approvals::recorded(ApprovalOperation::Approve))->toHaveCount(1);
    });
});
