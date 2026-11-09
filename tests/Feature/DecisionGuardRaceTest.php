<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalRequestVoided;
use RoundlyConsulting\Approvals\Events\ApprovalThresholdUnreachable;
use RoundlyConsulting\Approvals\Exceptions\ChangedApprovalSubjectException;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\LiveDecisions;
use RoundlyConsulting\Approvals\Tests\Fixtures\InvoiceTestModel;
use RoundlyConsulting\Approvals\Tests\Fixtures\MemberTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/*
|--------------------------------------------------------------------------
| Decisions racing a replacement, a void or a threshold re-check
|--------------------------------------------------------------------------
|
| A decision resolves where it lands, then writes under the round's row lock. A flat
| replacement, a void and a re-check that closes the round take the same lock, so
| whichever lands in between is seen when the decision reaches the lock: the decision
| is refused rather than recorded on a round that changed under it. These replay each
| interleaving deterministically (the suite's real-engine legs run them on Postgres and
| MySQL too), and pin that each guard takes the lock inside its own transaction.
|
*/

afterEach(fn () => LockRecorder::flush());

/**
 * Run `$interloper` once, when the decision first reads its slot — after it resolved
 * where it lands (and passed the fingerprint pre-check), before its transaction takes
 * the round's lock.
 */
function interleaveBeforeTheWrite(Closure $interloper): void
{
    app()->instance(LiveDecisions::class, new class($interloper)
    {
        private bool $ran = false;

        public function __construct(private readonly Closure $interloper) {}

        public function in(DecisionTarget $target): ?Approval
        {
            if (! $this->ran) {
                $this->ran = true;
                ($this->interloper)();
            }

            return (new LiveDecisions)->in($target);
        }
    });
}

/**
 * Close the round behind the caller's back the first time it is read from the database:
 * a concurrent decision resolving it between the guard's first read and its lock.
 */
function resolveConcurrentlyOnFirstRead(ApprovalRequest $round): void
{
    $done = false;

    Event::listen('eloquent.retrieved: '.ApprovalRequest::class, function (ApprovalRequest $read) use ($round, &$done): void {
        if ($done || ! $read->is($round)) {
            return;
        }

        $done = true;

        $read->newQuery()->whereKey($read->getKey())->update(['status' => ApprovalStatus::Approved->value]);
    });
}

describe('a decision racing a guard', function (): void {
    it('refuses a decision by an approver a flat replacement removed', function (string $verb): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($release)->from([$a, $b])->open();

        interleaveBeforeTheWrite(fn () => Approvals::for($release)->within($round)->replaceFlatApprovers([$a, $m]));

        expect(fn () => match ($verb) {
            'approve' => $b->approve($release),
            'reject' => $b->reject($release),
            'ask' => Approvals::for($release)->as($b)->ask(),
        })->toThrow(UnauthorizedApprovalException::class, 'is not a named approver')
            ->and(Approval::query()->withTrashed()->whereMorphedTo('actor', $b)->count())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with(['approve', 'reject', 'ask']);

    it('still records a decision by an approver the flat replacement keeps', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::request($release)->from([$a, $b])->open();

        interleaveBeforeTheWrite(fn () => Approvals::for($release)->replaceFlatApprovers([$a, $m]));

        expect($a->approve($release)->status)->toBe(ApprovalStatus::Approved);
    });

    it('refuses a decision on a round voided after it resolved its target', function (string $verb): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        $round = Approvals::request($invoice)->from([$reviewer, ReviewerTestModel::create()])->open();

        interleaveBeforeTheWrite(function () use ($invoice): void {
            $invoice->update(['amount' => 200]);
            Approvals::for($invoice)->voidIfChanged();
        });

        expect(fn () => match ($verb) {
            'approve' => $reviewer->approve($invoice),
            'reject' => $reviewer->reject($invoice),
            'ask' => Approvals::for($invoice)->as($reviewer)->ask(),
        })->toThrow(ClosedApprovalRequestException::class)
            ->and(Approval::query()->withTrashed()->count())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    })->with(['approve', 'reject', 'ask']);

    it('refuses a decision whose subject changed after the pre-check, under the lock', function (string $mode): void {
        config()->set('approvals.fingerprint.on_change', $mode);

        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        $round = Approvals::request($invoice)->from([$reviewer])->open();

        interleaveBeforeTheWrite(fn () => InvoiceTestModel::query()->whereKey($invoice->getKey())->update(['amount' => 300]));

        expect(fn () => $reviewer->approve($invoice))->toThrow(ChangedApprovalSubjectException::class)
            ->and(Approval::query()->withTrashed()->count())->toBe(0)
            // Refused under the lock, never voided there: the void would roll back with it.
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with(['refuse', 'void']);

    it('records a decision whose subject changed after the pre-check when on_change is ignore', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $reviewer = ReviewerTestModel::create();
        Approvals::request($invoice)->from([$reviewer])->open();

        interleaveBeforeTheWrite(fn () => InvoiceTestModel::query()->whereKey($invoice->getKey())->update(['amount' => 300]));

        expect($reviewer->approve($invoice)->subject_fingerprint)->toBe('amount:300');
    });

    it('refuses a decision on a round a re-check closed after it resolved its target', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $gone] = [MemberTestModel::create(), MemberTestModel::create()];
        $round = Approvals::request($release)->from([$a, $gone])->open();

        interleaveBeforeTheWrite(function () use ($release, $gone): void {
            $gone->delete();
            Approvals::for($release)->recheckThreshold(ApprovalStatus::Rejected);
        });

        expect(fn () => $a->approve($release))->toThrow(ClosedApprovalRequestException::class)
            ->and(Approval::query()->withTrashed()->count())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Rejected);
    });
});

describe('two writers closing one round', function (): void {
    it('lets one claim the round and the other none', function (): void {
        $round = Approvals::request(ReleaseTestModel::create())->from([ReviewerTestModel::create()])->open();
        $copy = ApprovalRequest::query()->findOrFail($round->getKey());

        expect($round->claimFinal(ApprovalStatus::Cancelled))->toBeTrue()
            ->and($copy->claimFinal(ApprovalStatus::Rejected))->toBeFalse()
            ->and($copy->status)->toBe(ApprovalStatus::Cancelled);
    });

    it('voids nothing when a concurrent decision resolved the round first', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $round = Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();
        $invoice->update(['amount' => 200]);

        resolveConcurrentlyOnFirstRead($round);

        Event::fake([ApprovalRequestVoided::class, ApprovalRequestResolved::class]);

        expect(Approvals::for($invoice)->voidIfChanged())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Approved);

        Event::assertNotDispatched(ApprovalRequestVoided::class);
        Event::assertNotDispatched(ApprovalRequestResolved::class);
    });

    it('closes nothing when a concurrent decision resolved the round before the re-check locked it', function (): void {
        $release = ReleaseTestModel::create();
        $gone = MemberTestModel::create();
        $round = Approvals::request($release)->from([$gone])->open();
        $gone->delete();

        resolveConcurrentlyOnFirstRead($round);

        Event::fake([ApprovalThresholdUnreachable::class, ApprovalRequestResolved::class]);

        expect(Approvals::for($release)->recheckThreshold(ApprovalStatus::Cancelled))->toBeNull()
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Approved);

        Event::assertNotDispatched(ApprovalThresholdUnreachable::class);
        Event::assertNotDispatched(ApprovalRequestResolved::class);
    });

    it('voids a round once when two voids race', function (): void {
        $invoice = InvoiceTestModel::create(['amount' => 100]);
        $round = Approvals::request($invoice)->from([ReviewerTestModel::create()])->open();
        $invoice->update(['amount' => 200]);

        Event::fake([ApprovalRequestVoided::class, ApprovalRequestResolved::class]);

        expect(Approvals::for($invoice)->within($round)->voidIfChanged())->toBe(1)
            // The second void holds the copy the first one read: it finds the round closed.
            ->and(Approvals::for($invoice)->within($round)->voidIfChanged())->toBe(0);

        Event::assertDispatchedTimes(ApprovalRequestVoided::class, 1);
        Event::assertDispatchedTimes(ApprovalRequestResolved::class, 1);
    });
});

describe('the lock', function (): void {
    /**
     * @return list<array{sql: string, transactionDepth: int}>
     */
    function roundLocksDuring(Closure $guard): array
    {
        $connection = DB::connection();
        $connection->setQueryGrammar(new LockRecordingGrammar($connection));
        LockRecorder::flush();
        LockRecorder::listenForMarkers();

        $guard();

        return array_values(array_filter(
            LockRecorder::recorded(),
            static fn (array $lock): bool => str_contains($lock['sql'], 'approval_requests'),
        ));
    }

    it('is taken on the round inside each guard\'s own transaction', function (string $guard): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The lock-recording grammar is SQLite\'s; a real engine takes the real lock.');
        }

        $invoice = InvoiceTestModel::create(['amount' => 1]);
        $gone = MemberTestModel::create();
        Approvals::request($invoice)->from([$gone])->open();
        $invoice->update(['amount' => 2]);
        $gone->delete();

        $locks = roundLocksDuring(fn () => match ($guard) {
            'replaceFlatApprovers' => Approvals::for($invoice)->replaceFlatApprovers([ReviewerTestModel::create()]),
            'voidIfChanged' => Approvals::for($invoice)->voidIfChanged(),
            'recheckThreshold' => Approvals::for($invoice)->recheckThreshold(ApprovalStatus::Cancelled),
        });

        expect($locks)->not->toBeEmpty()
            ->and($locks[0]['transactionDepth'])->toBeGreaterThanOrEqual(1);
    })->with(['replaceFlatApprovers', 'voidIfChanged', 'recheckThreshold']);
});
