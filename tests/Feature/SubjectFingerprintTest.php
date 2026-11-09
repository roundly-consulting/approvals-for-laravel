<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Approvals\Actions\VoidChangedApprovalRequestsAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalRequestVoided;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ChangedApprovalSubjectException;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\Fixtures\InvoiceTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
|--------------------------------------------------------------------------
| Tying a round to one version of its subject
|--------------------------------------------------------------------------
|
| A subject implementing ProvidesApprovalFingerprint has its fingerprint stored on the
| round when it opens and on every decision. `voidIfChanged()` voids a round whose
| subject changed since; `approvals.fingerprint.on_change` refuses (and voids) decisions
| on one.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

describe('storing the fingerprint', function (): void {
    it('stores it on the round and on every decision and ask', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        [$a, $b, $c, $d] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($invoice)->from([$a, $b, $c, $d])->quorum(2)->open();

        $approval = $a->approve($invoice);
        $rejection = $b->reject($invoice);
        $ask = Approvals::for($invoice)->as($c)->ask();

        expect($round->fresh()?->subject_fingerprint)->toBe('amount:100')
            ->and($approval->fresh()?->subject_fingerprint)->toBe('amount:100')
            ->and($rejection->fresh()?->subject_fingerprint)->toBe('amount:100')
            ->and($ask->fresh()?->subject_fingerprint)->toBe('amount:100');
    });

    it('stamps the version a decision was made on, read from the stored subject', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();

        // The model in memory is stale: the stored subject is what the approver signs off on.
        InvoiceTestModel::query()->whereKey($invoice->getKey())->update(['amount' => 250]);

        expect($reviewer->approve($invoice)->subject_fingerprint)->toBe('amount:250');
    });

    it('stores it on staged rounds and on rounds from a workflow preset', function (): void {
        config()->set('approvals.workflows.payout', ['rule' => ApprovalRule::Any->value]);

        $staged = Approvals::request(InvoiceTestModel::create(['amount' => 5]))
            ->stages([new StageDefinition([ReviewerTestModel::create()])])->open();
        $preset = Approvals::request(InvoiceTestModel::create(['amount' => 6]))
            ->workflow('payout')->open([ReviewerTestModel::create()]);

        expect($staged->fresh()?->subject_fingerprint)->toBe('amount:5')
            ->and($preset->fresh()?->subject_fingerprint)->toBe('amount:6');
    });

    it('stores nothing for a subject that provides no fingerprint', function (): void {
        $release = ReleaseTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $round = Approvals::request($release)->from([$reviewer])->open();

        expect($round->fresh()?->subject_fingerprint)->toBeNull()
            ->and($reviewer->approve($release)->subject_fingerprint)->toBeNull()
            ->and(Approvals::subjectChanged($release))->toBeFalse()
            ->and(Approvals::for($release)->voidIfChanged())->toBe(0);
    });

    it('refuses a fingerprint longer than its column', function (): void {
        $invoice = new class extends InvoiceTestModel
        {
            public function approvalFingerprint(): string
            {
                return str_repeat('x', 256);
            }
        };
        $invoice->save();

        expect(fn () => Approvals::request($invoice)->from([ReviewerTestModel::create()])->open())
            ->toThrow(InvalidApprovalRequestException::class, 'longer than 255 characters');
    });

    it('reads an unsaved subject as it is in memory', function (): void {
        $round = Approvals::request(new InvoiceTestModel(['amount' => 7]))->any()->open();

        expect($round->fresh()?->subject_fingerprint)->toBe('amount:7');
    });
});

describe('telling whether the subject changed', function (): void {
    it('reports a change of the open round\'s subject', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);

        expect(Approvals::subjectChanged($invoice))->toBeFalse();

        Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();

        expect(Approvals::subjectChanged($invoice))->toBeFalse();

        $invoice->update(['amount' => 120]);

        expect(Approvals::subjectChanged($invoice))->toBeTrue()
            ->and(app(ApprovalsManager::class)->subjectChanged($invoice))->toBeTrue();
    });
});

describe('voiding a changed round', function (): void {
    it('voids the open round whose subject changed and announces it', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($invoice)->from([$a, $b])->open();
        $a->approve($invoice);
        $ask = Approvals::for($invoice)->as($b)->ask();

        $invoice->update(['amount' => 999]);

        Event::fake([ApprovalRequestVoided::class, ApprovalRequestResolved::class, ApprovalStatusChanged::class, ApprovalCancelled::class]);

        expect(Approvals::for($invoice)->voidIfChanged())->toBe(1)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($round->fresh()?->resolved_at)->not->toBeNull()
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled);

        Event::assertDispatched(ApprovalRequestVoided::class, fn (ApprovalRequestVoided $event): bool => $event->request->is($round)
            && $event->openedWith === 'amount:100'
            && $event->current === 'amount:999'
            && $event->request->status === ApprovalStatus::Cancelled);
        Event::assertDispatchedTimes(ApprovalRequestResolved::class, 1);
        Event::assertDispatched(ApprovalCancelled::class, fn (ApprovalCancelled $event): bool => $event->approval->is($ask));

        // A decision after the void reaches a closed round.
        expect(fn () => $b->approve($invoice))->toThrow(ClosedApprovalRequestException::class);
    });

    it('leaves an unchanged round, a closed round and a round without a fingerprint alone', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $round = Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();

        expect(Approvals::for($invoice)->voidIfChanged())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);

        $round->forceFill(['subject_fingerprint' => null])->save();
        $invoice->update(['amount' => 1]);

        expect(Approvals::for($invoice)->voidIfChanged())->toBe(0);

        $closed = InvoiceTestModel::create(['amount' => 1]);
        $closedRound = Approvals::request($closed)->from([ReviewerTestModel::create()])->open();
        Approvals::for($closed)->close();
        $closed->update(['amount' => 2]);

        Event::fake([ApprovalRequestVoided::class]);

        expect(Approvals::for($closed)->within($closedRound)->voidIfChanged())->toBe(0);

        Event::assertNotDispatched(ApprovalRequestVoided::class);
    });

    it('voids only the pinned round', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $older = Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();
        $newer = Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();

        $invoice->update(['amount' => 5]);

        expect(Approvals::for($invoice)->within($older)->voidIfChanged())->toBe(1)
            ->and($older->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($newer->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(Approvals::for($invoice)->voidIfChanged())->toBe(1);
    });

    it('lapses an overdue round as expired instead of voiding it', function (): void {
        CarbonImmutable::setTestNow('2026-11-09 12:00:00');

        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $round = Approvals::request($invoice)->from([ReviewerTestModel::create()])->expiresIn(60)->open();

        $invoice->update(['amount' => 5]);
        CarbonImmutable::setTestNow('2026-11-09 13:00:00');

        Event::fake([ApprovalRequestVoided::class]);

        expect(Approvals::for($invoice)->voidIfChanged())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Expired);

        Event::assertNotDispatched(ApprovalRequestVoided::class);
    });

    it('refuses a round of another subject', function (): void {
        $invoice = InvoiceTestModel::create();
        $foreign = Approvals::request(InvoiceTestModel::create())->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($invoice)->within($foreign)->voidIfChanged())
            ->toThrow(InvalidApprovalRequestException::class, 'does not belong to the approvable');
    });

    it('refuses an actor and decision settings', function (string $setting): void {
        $reviewer = ReviewerTestModel::create();
        $pending = Approvals::for(InvoiceTestModel::create());

        match ($setting) {
            'as()' => $pending->as($reviewer),
            'because()' => $pending->because('why'),
            'weight()' => $pending->weight(2),
            'expiresIn() / expiringAt()' => $pending->expiresIn(60),
        };

        expect(fn () => $pending->voidIfChanged())
            ->toThrow(InvalidApprovalRequestException::class, "voidIfChanged() involves no actor and records no decision, so [{$setting}]");
    })->with(['as()', 'because()', 'weight()', 'expiresIn() / expiringAt()']);

    it('voids through the raw action', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 1]);
        $round = Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();
        $invoice->update(['amount' => 2]);

        expect(app(VoidChangedApprovalRequestsAction::class)->execute($invoice))->toBe(1)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    });
});

describe('decisions on a changed subject', function (): void {
    it('records them by default, as before', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        Approvals::request($invoice)->from([$reviewer])->open();

        $invoice->update(['amount' => 5]);

        expect($reviewer->approve($invoice)->subject_fingerprint)->toBe('amount:5')
            ->and(Approvals::status($invoice))->toBe(ApprovalStatus::Approved);
    });

    it('refuses them, leaving the round open, under "refuse"', function (string $verb): void {
        config()->set('approvals.fingerprint.on_change', 'refuse');

        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        $round = Approvals::request($invoice)->from([$reviewer])->open();

        $invoice->update(['amount' => 5]);

        $thrown = null;

        try {
            match ($verb) {
                'approve' => $reviewer->approve($invoice),
                'reject' => $reviewer->reject($invoice),
                'ask' => Approvals::for($invoice)->as($reviewer)->ask(),
                'toggle' => $reviewer->toggleApproval($invoice),
            };
        } catch (ChangedApprovalSubjectException $e) {
            $thrown = $e;
        }

        expect($thrown?->voided)->toBeFalse()
            ->and($thrown?->request->is($round))->toBeTrue()
            ->and($thrown?->getMessage())->toContain('Void it with voidIfChanged()')
            ->and(Approval::query()->count())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with(['approve', 'reject', 'ask', 'toggle']);

    it('refuses them and voids the round under "void"', function (): void {
        config()->set('approvals.fingerprint.on_change', 'void');

        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        $round = Approvals::request($invoice)->from([$reviewer])->open();

        $invoice->update(['amount' => 5]);

        Event::fake([ApprovalRequestVoided::class]);

        expect(fn () => $reviewer->approve($invoice))->toThrow(ChangedApprovalSubjectException::class, 'the round was voided')
            ->and(Approval::query()->count())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Cancelled);

        Event::assertDispatchedTimes(ApprovalRequestVoided::class, 1);
    });

    it('takes decisions on an unchanged subject and on standalone decisions', function (): void {
        config()->set('approvals.fingerprint.on_change', 'refuse');

        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        Approvals::request($invoice)->from([$reviewer])->open();

        $standalone = InvoiceTestModel::create(['amount' => 1]);

        expect($reviewer->approve($invoice)->status)->toBe(ApprovalStatus::Approved)
            ->and($reviewer->approve($standalone)->status)->toBe(ApprovalStatus::Approved);
    });

    it('throws on an unknown on_change value, and reads blank as the default', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        Approvals::request($invoice)->from([$reviewer])->open();

        config()->set('approvals.fingerprint.on_change', 'block');

        expect(fn () => $reviewer->approve($invoice))->toThrow(InvalidConfigurationException::class, 'approvals.fingerprint.on_change');

        config()->set('approvals.fingerprint.on_change', '');
        $invoice->update(['amount' => 1]);

        expect($reviewer->approve($invoice)->status)->toBe(ApprovalStatus::Approved);
    });
});

describe('the fake', function (): void {
    it('records voids and asserts on them', function (): void {
        $fake = Approvals::fake();

        $invoice = InvoiceTestModel::create(['amount' => 1]);
        $other = InvoiceTestModel::create(['amount' => 1]);
        Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();

        Approvals::assertNothingVoided();

        app(ApprovalsManager::class)->for($invoice)->voidIfChanged();

        expect($fake->recorded(ApprovalOperation::Void))->toHaveCount(1);

        // A void that voided nothing is no void.
        Approvals::assertNothingVoided();
        expect(fn () => Approvals::assertVoided($invoice))->toThrow(AssertionFailedError::class, 'Expected an approval round of the subject to be voided');

        $invoice->update(['amount' => 2]);
        Approvals::for($invoice)->voidIfChanged();

        Approvals::assertVoided($invoice);

        expect(fn () => Approvals::assertVoided($other))->toThrow(AssertionFailedError::class)
            ->and(fn () => Approvals::assertNothingVoided())->toThrow(AssertionFailedError::class, '1 were');
    });
});
