<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\CloseApprovalRequestAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\LiveDecisions;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| Closing a round from outside
|--------------------------------------------------------------------------
|
| A host that owns the subject's lifecycle (requests-for-laravel cancels and
| expires requests) closes the open round through Approvals::for($subject)->close().
| It closes the round the way the engine closes one when it resolves it: the
| conditional status write, the outstanding asks retired, then the resolution
| events. Before, a host could only copy that by hand and missed the asks.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * Record every approvals event in dispatch order, as [event, subject, from → to].
 *
 * @return ArrayObject<int, string>
 */
function recordApprovalEventsForClose(): ArrayObject
{
    $log = new ArrayObject;

    $label = static fn (object $model): string => ($model instanceof ApprovalRequest ? 'request#' : 'approval#').$model->getKey();

    Event::listen(ApprovalCancelled::class, static function (ApprovalCancelled $event) use ($log, $label): void {
        $log[] = 'cancelled '.$label($event->approval);
    });

    Event::listen(ApprovalRequestResolved::class, static function (ApprovalRequestResolved $event) use ($log, $label): void {
        $log[] = 'resolved '.$label($event->request).' '.$event->request->status->value;
    });

    Event::listen(ApprovalStatusChanged::class, static function (ApprovalStatusChanged $event) use ($log, $label): void {
        $log[] = 'changed '.$label($event->subject).' '.$event->from->value.'→'.$event->to->value;
    });

    return $log;
}

/**
 * Fake every approvals event (and nothing else: Eloquent's own model events keep firing
 * untouched), so a test can assert no approvals event fired.
 */
function fakeApprovalEventsForClose(): void
{
    Event::fake(array_map(
        static fn (string $file): string => 'RoundlyConsulting\\Approvals\\Events\\'.basename($file, '.php'),
        glob(__DIR__.'/../../src/Events/*.php') ?: [],
    ));
}

describe('closing the open round', function (): void {
    it('closes it as cancelled by default', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(Approvals::for($release)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($request->fresh()?->resolved_at?->toDateTimeString())->toBe('2026-10-10 12:00:00')
            ->and(Approvals::status($release))->toBe(ApprovalStatus::Cancelled)
            ->and($release->isPendingApproval())->toBeFalse();
    });

    it('closes it as expired', function (): void {
        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(Approvals::for($release)->close(ApprovalStatus::Expired))->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired);
    });

    it('retires an outstanding ask so it can no longer be answered or withdrawn', function (): void {
        $release = ReleaseTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$alice, $bob])->open();
        $ask = Approvals::for($release)->as($alice)->ask();

        Approvals::for($release)->close();

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->live)->toBeNull()
            ->and(Approvals::for($release)->hasPending())->toBeFalse()
            ->and($release->pendingApprovals())->toHaveCount(0)
            ->and(Blade::check('pendingApproval', $release))->toBeFalse()
            ->and(fn () => $alice->approve($release))->toThrow(ClosedApprovalRequestException::class, 'is closed (cancelled)')
            ->and(fn () => Approvals::for($release)->as($alice)->cancel())->toThrow(ClosedApprovalRequestException::class)
            ->and(Approvals::expire())->toBe(0);
    });

    it('leaves the decisions already made alone', function (): void {
        $release = ReleaseTestModel::create();
        [$alice, $bob, $carol] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$alice, $bob, $carol])->open();
        $approval = $alice->approve($release);
        $ask = Approvals::for($release)->as($bob)->ask();

        Approvals::for($release)->close();

        expect($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->fresh()?->live)->toBeTrue()
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    });

    it('fires the engine resolution events, the retired asks first', function (): void {
        $release = ReleaseTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$alice, $bob])->open();
        $askAlice = Approvals::for($release)->as($alice)->ask();
        $askBob = Approvals::for($release)->as($bob)->ask();

        // What a listener of the resolution sees: no ask is left waiting on the round.
        $liveAsksWhenResolved = null;
        Event::listen(ApprovalRequestResolved::class, function (ApprovalRequestResolved $event) use (&$liveAsksWhenResolved): void {
            $liveAsksWhenResolved = $event->request->decisions()->where('status', ApprovalStatus::Pending)->live()->count();
        });

        $log = recordApprovalEventsForClose();

        Approvals::for($release)->close();

        $a = 'approval#'.$askAlice->getKey();
        $b = 'approval#'.$askBob->getKey();
        $r = 'request#'.$request->getKey();

        expect($log->getArrayCopy())->toBe([
            "cancelled {$a}",
            "changed {$a} pending→cancelled",
            "cancelled {$b}",
            "changed {$b} pending→cancelled",
            "resolved {$r} cancelled",
            "changed {$r} pending→cancelled",
        ])->and($liveAsksWhenResolved)->toBe(0);
    });

    it('closes as expired with the very events the engine fires when a round lapses', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        // The engine's own path: a round past its expiry, lapsed by the sweep.
        $lapsing = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();
        Approvals::request($lapsing)->from([$lead])->expiresIn(60)->open();
        Approvals::for($lapsing)->as($lead)->ask();

        // The host's path: an identical round, closed as expired from outside.
        $closing = ReleaseTestModel::create();
        Approvals::request($closing)->from([$lead])->open();
        Approvals::for($closing)->as($lead)->ask();

        CarbonImmutable::setTestNow('2026-10-10 13:00:00');

        $shape = static fn (array $log): array => array_map(
            static fn (string $line): string => (string) preg_replace('/#\d+/', '#', $line),
            $log,
        );

        $engine = recordApprovalEventsForClose();
        Approvals::expire();
        $engineEvents = $shape($engine->getArrayCopy());

        Event::forget(ApprovalCancelled::class);
        Event::forget(ApprovalRequestResolved::class);
        Event::forget(ApprovalStatusChanged::class);

        $host = recordApprovalEventsForClose();
        Approvals::for($closing)->close(ApprovalStatus::Expired);

        expect($shape($host->getArrayCopy()))->toBe($engineEvents)
            ->and($engineEvents)->toBe([
                'cancelled approval#',
                'changed approval# pending→cancelled',
                'resolved request# expired',
                'changed request# pending→expired',
            ]);
    });

    it('retires the asks of a staged round', function (): void {
        $release = ReleaseTestModel::create();
        [$eng, $product] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->stages([
            new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
            new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
        ])->open();

        $ask = Approvals::for($release)->as($eng)->ask();

        expect(Approvals::for($release)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and(Approvals::currentStage($release))->toBeNull()
            ->and(fn () => $eng->approve($release))->toThrow(ClosedApprovalRequestException::class);
    });

    it('lapses a round already past its expiry as expired, as status() reports it', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->expiresIn(60)->open();

        CarbonImmutable::setTestNow('2026-10-10 13:00:00');

        expect(Approvals::status($release))->toBe(ApprovalStatus::Expired)
            ->and(Approvals::for($release)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and(Approvals::status($release))->toBe(ApprovalStatus::Expired);
    });
});

describe('which rounds it closes', function (): void {
    it('closes every open round of the subject', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $older = Approvals::request($release)->from([$lead])->open();
        $newer = Approvals::request($release)->from([$lead])->open();
        $other = Approvals::request(ReleaseTestModel::create())->from([$lead])->open();

        expect(Approvals::for($release)->close())->toBe(2)
            ->and($older->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($newer->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($other->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    it('closes only the round pinned with within()', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $older = Approvals::request($release)->from([$lead])->open();
        $newer = Approvals::request($release)->from([$lead])->open();

        expect(Approvals::for($release)->within($older)->close(ApprovalStatus::Expired))->toBe(1)
            ->and($older->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and($newer->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    it('refuses a pinned round of another subject', function (): void {
        $release = ReleaseTestModel::create();
        $foreign = Approvals::request(ReleaseTestModel::create())->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->within($foreign)->close())
            ->toThrow(InvalidApprovalRequestException::class, 'does not belong to the approvable')
            ->and($foreign->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    it('refuses an outcome that only decisions reach', function (ApprovalStatus $outcome): void {
        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->close($outcome))
            ->toThrow(InvalidStatusTransitionException::class, "[{$outcome->value}]")
            ->and(fn () => $request->close($outcome))
            ->toThrow(InvalidStatusTransitionException::class)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with([ApprovalStatus::Pending, ApprovalStatus::Approved, ApprovalStatus::Rejected]);

    it('refuses such an outcome even with no round open', function (): void {
        expect(fn () => Approvals::for(ReleaseTestModel::create())->close(ApprovalStatus::Approved))
            ->toThrow(InvalidStatusTransitionException::class);
    });
});

describe('a round that is already closed', function (): void {
    it('is a no-op: closing it again changes nothing and fires nothing', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        Approvals::for($release)->close();

        CarbonImmutable::setTestNow('2026-10-10 13:00:00');
        fakeApprovalEventsForClose();

        expect(Approvals::for($release)->close(ApprovalStatus::Expired))->toBe(0)
            ->and(Approvals::for($release)->within($request)->close())->toBe(0)
            ->and($request->close(ApprovalStatus::Expired))->toBeFalse()
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($request->fresh()?->resolved_at?->toDateTimeString())->toBe('2026-10-10 12:00:00');

        Event::assertNothingDispatched();
    });

    it('keeps the outcome its decisions reached', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->open();
        $lead->approve($release);

        fakeApprovalEventsForClose();

        expect(Approvals::for($release)->close())->toBe(0)
            ->and(Approvals::for($release)->within($request)->close())->toBe(0)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved);

        Event::assertNothingDispatched();
    });

    it('is a no-op on a subject that never had a round', function (): void {
        fakeApprovalEventsForClose();

        expect(Approvals::for(ReleaseTestModel::create())->close())->toBe(0);

        Event::assertNothingDispatched();
    });
});

describe('the status write guard', function (): void {
    it('lets a stale copy close nothing once a decision resolved the round', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->open();

        // Another process loaded the round while it was still open.
        $stale = ApprovalRequest::query()->findOrFail($request->getKey());

        $lead->approve($release);

        CarbonImmutable::setTestNow('2026-10-10 12:05:00');
        fakeApprovalEventsForClose();

        expect(Approvals::for($release)->within($stale)->close())->toBe(0)
            ->and($stale->close(ApprovalStatus::Cancelled))->toBeFalse()
            ->and($stale->status)->toBe(ApprovalStatus::Approved)
            ->and($request->fresh()?->resolved_at?->toDateTimeString())->toBe('2026-10-10 12:00:00');

        Event::assertNothingDispatched();
    });

    it('closes nothing when a decision resolves the round between the read and the write', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->any()->open();
        $ask = Approvals::for($release)->as($lead)->ask();

        // The round resolves (approved) right after the close has loaded it as open.
        $reads = 0;
        ApprovalRequest::retrieved(function () use ($request, &$reads): void {
            if ($reads++ === 0) {
                $request->newQuery()->whereKey($request->getKey())->update(['status' => ApprovalStatus::Approved->value]);
            }
        });

        Event::fake([ApprovalRequestResolved::class, ApprovalStatusChanged::class, ApprovalCancelled::class]);

        expect(Approvals::for($release)->close())->toBe(0)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Pending);

        Event::assertNothingDispatched();
    });

    it('refuses a decision when the round closes between its read and its write', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::request($release)->from([$lead, $qa])->open();

        // The decision resolved its target as open; the host closes the round before the
        // decision's transaction takes the request lock.
        app()->instance(LiveDecisions::class, new class($release)
        {
            private bool $closed = false;

            public function __construct(private readonly ReleaseTestModel $release) {}

            public function in(DecisionTarget $target): ?Approval
            {
                if (! $this->closed) {
                    $this->closed = true;
                    Approvals::for($this->release)->close();
                }

                return (new LiveDecisions)->in($target);
            }
        });

        expect(fn () => $lead->approve($release))->toThrow(ClosedApprovalRequestException::class, 'is closed (cancelled)')
            ->and(Approval::query()->withTrashed()->count())->toBe(0);
    });
});

describe('every way in', function (): void {
    it('closes through an injected manager', function (): void {
        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(app(ApprovalsManager::class)->for($release)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    });

    it('closes through the raw action', function (): void {
        $release = ReleaseTestModel::create();
        $older = Approvals::request($release)->from([ReviewerTestModel::create()])->open();
        $newer = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        $action = app(CloseApprovalRequestAction::class);

        expect($action->execute($release, ApprovalStatus::Expired, $older))->toBe(1)
            ->and($older->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and($action->execute($release))->toBe(1)
            ->and($newer->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    });
});
