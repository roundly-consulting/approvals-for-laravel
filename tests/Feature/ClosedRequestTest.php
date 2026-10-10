<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ApprovalsException;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\LiveDecisions;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| A closed request accepts no more decisions
|--------------------------------------------------------------------------
|
| Once a subject's latest request is closed — approved, rejected, cancelled or
| expired — a late decision on the subject used to land as a standalone decision (or,
| pinned with within(), inside the closed request). Every decision path now refuses
| it until a new round opens, and records nothing.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

function closeRound(ApprovalRequest $request, ApprovalStatus $status): ApprovalRequest
{
    // How a host closes a round it owns (requests-for-laravel cancels/expires this way).
    $request->newQuery()->whereKey($request->getKey())->update(['status' => $status->value]);

    return $request->refresh();
}

describe('a late decision on the subject', function (): void {
    it('is refused once the round resolved', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = $release->requestApproval([$lead, $qa], ApprovalRule::Any);
        $lead->approve($release);

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and(fn () => $qa->reject($release))->toThrow(ClosedApprovalRequestException::class, 'is closed (approved)')
            ->and(fn () => $lead->reject($release))->toThrow(ClosedApprovalRequestException::class)
            ->and(Approval::query()->count())->toBe(1)
            ->and($lead->hasRejected($release))->toBeFalse();
    });

    it('is refused once the round was cancelled', function (string $verb): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        closeRound($release->requestApproval([$lead]), ApprovalStatus::Cancelled);

        expect(fn () => match ($verb) {
            'approve' => $lead->approve($release),
            'reject' => $lead->reject($release),
            'toggle' => $lead->toggleApproval($release),
            'ask' => Approvals::for($release)->as($lead)->ask(),
        })->toThrow(ClosedApprovalRequestException::class, 'is closed (cancelled)')
            ->and(Approval::query()->withTrashed()->count())->toBe(0);
    })->with(['approve', 'reject', 'toggle', 'ask']);

    it('is refused once the round lapsed, even before the sweep', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->expiresIn(60)->open();

        CarbonImmutable::setTestNow('2026-09-30 12:00:00');

        expect(fn () => $lead->approve($release))->toThrow(ClosedApprovalRequestException::class, 'is closed (expired)')
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and(Approval::query()->count())->toBe(0);
    });

    it('is refused for a delegate acting for a named approver', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        closeRound($release->requestApproval([$boss]), ApprovalStatus::Cancelled);
        $boss->delegateApprovalsTo($deputy);

        expect(fn () => $deputy->approve($release))->toThrow(ClosedApprovalRequestException::class)
            ->and(Approval::query()->count())->toBe(0);
    });

    it('is accepted again once a new round opens', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        closeRound($release->requestApproval([$lead]), ApprovalStatus::Cancelled);
        $fresh = $release->requestApproval([$lead]);

        $approval = $lead->approve($release);

        expect($approval->approval_request_id)->toBe($fresh->getKey())
            ->and($fresh->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('stays standalone on a model that never had a request', function (): void {
        $approval = ReviewerTestModel::create()->approve(DeploymentTestModel::create());

        expect($approval->approval_request_id)->toBeNull()
            ->and($approval->status)->toBe(ApprovalStatus::Approved);
    });
});

describe('repeating the decision already held in the closed round', function (): void {
    it('is an idempotent no-op, as in an open round', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = $release->requestApproval([$lead]);
        $first = $lead->approve($release);

        Event::fake([ApprovalApproved::class, ApprovalStatusChanged::class]);

        $again = $lead->approve($release);
        $pinned = Approvals::for($release)->as($lead)->within($request)->approve();

        expect($again->is($first))->toBeTrue()
            ->and($pinned->is($first))->toBeTrue()
            ->and(Approval::query()->count())->toBe(1);

        Event::assertNothingDispatched();
    });

    it('is a no-op for a repeated rejection, and for the delegate who decided', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $release->requestApproval([$boss]);
        $boss->delegateApprovalsTo($deputy);
        $rejection = $deputy->reject($release);

        expect($deputy->reject($release)->is($rejection))->toBeTrue()
            ->and($boss->reject($release)->is($rejection))->toBeTrue()
            ->and(fn () => $deputy->approve($release))->toThrow(ClosedApprovalRequestException::class)
            ->and(Approval::query()->count())->toBe(1);
    });

    it('is refused once the held decision lapsed', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $release->requestApproval([$lead]);
        Approvals::for($release)->as($lead)->expiresIn(60)->approve();

        CarbonImmutable::setTestNow('2026-09-30 12:00:00');

        expect(fn () => $lead->approve($release))->toThrow(ClosedApprovalRequestException::class);
    });
});

describe('a decision pinned to a closed request', function (): void {
    it('is refused, whatever closed it', function (ApprovalStatus $status): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = closeRound($release->requestApproval([$lead]), $status);

        expect(fn () => Approvals::for($release)->as($lead)->within($request)->approve())
            ->toThrow(ClosedApprovalRequestException::class, "is closed ({$status->value})")
            ->and(fn () => Approvals::for($release)->as($lead)->within($request)->reject())
            ->toThrow(ClosedApprovalRequestException::class)
            ->and(fn () => Approvals::for($release)->as($lead)->within($request)->ask())
            ->toThrow(ClosedApprovalRequestException::class)
            ->and(fn () => Approvals::for($release)->as($lead)->within($request)->toggle())
            ->toThrow(ClosedApprovalRequestException::class, "is closed ({$status->value})")
            ->and($request->decisions()->withTrashed()->count())->toBe(0);
    })->with([ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Cancelled, ApprovalStatus::Expired]);

    it('is refused even when the subject has another round open', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $closed = closeRound($release->requestApproval([$lead]), ApprovalStatus::Rejected);
        $release->requestApproval([$lead]);

        expect(fn () => Approvals::for($release)->as($lead)->within($closed)->approve())
            ->toThrow(ClosedApprovalRequestException::class)
            ->and($closed->decisions()->count())->toBe(0);
    });

    // toggle() used to ignore within() and approve the open later round instead.
    it('refuses a toggle on even when the subject has another round open', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $closed = closeRound($release->requestApproval([$lead]), ApprovalStatus::Rejected);
        $open = $release->requestApproval([$lead]);

        expect(fn () => Approvals::for($release)->as($lead)->within($closed)->toggle())
            ->toThrow(ClosedApprovalRequestException::class, 'is closed (rejected)')
            ->and(Approval::query()->withTrashed()->count())->toBe(0)
            ->and($open->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    // …and withdraw the approval held in the open later round.
    it('refuses a toggle off even when the subject has another round open', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $closed = closeRound($release->requestApproval([$lead]), ApprovalStatus::Cancelled);
        $open = $release->requestApproval([$lead, $qa], ApprovalRule::Unanimous);
        $held = $lead->approve($release);

        expect(fn () => Approvals::for($release)->as($lead)->within($closed)->toggle())
            ->toThrow(ClosedApprovalRequestException::class, 'is closed (cancelled)')
            ->and($held->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($held->fresh()?->approval_request_id)->toBe($open->getKey())
            ->and($lead->hasApproved($release))->toBeTrue();
    });
});

describe('withdrawing a decision of a closed round', function (): void {
    it('is refused, and the decision keeps counting', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = $release->requestApproval([$lead]);
        $approval = $lead->approve($release);

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and(fn () => $lead->cancelApproval($release))->toThrow(ClosedApprovalRequestException::class)
            ->and(fn () => Approvals::for($release)->as($lead)->within($request)->cancel())->toThrow(ClosedApprovalRequestException::class)
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($lead->hasApproved($release))->toBeTrue();
    });

    it('is refused for the delegate who decided it', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $release->requestApproval([$boss]);
        $boss->delegateApprovalsTo($deputy);
        $approval = $deputy->approve($release);

        expect($release->isApproved())->toBeTrue()
            ->and(fn () => $deputy->cancelApproval($release))->toThrow(ClosedApprovalRequestException::class)
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('only reaches the open round once a new one opens', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $release->requestApproval([$lead]);
        $old = $lead->approve($release);
        $fresh = $release->requestApproval([$lead, $qa]);

        expect($lead->cancelApproval($release))->toBeNull()
            ->and($old->fresh()?->status)->toBe(ApprovalStatus::Approved);

        $lead->approve($release);

        expect($lead->cancelApproval($release)?->approval_request_id)->toBe($fresh->getKey());
    });
});

describe('a round closing between the read and the write', function (): void {
    /**
     * Replays a concurrent resolution: the round closes right after the action resolved
     * its target as open, before the write's transaction starts.
     */
    function closeOnFirstRead(ApprovalRequest $request): void
    {
        app()->instance(LiveDecisions::class, new class($request)
        {
            private bool $closed = false;

            public function __construct(private readonly ApprovalRequest $request) {}

            public function in(DecisionTarget $target): ?Approval
            {
                if (! $this->closed) {
                    $this->closed = true;
                    closeRound($this->request, ApprovalStatus::Approved);
                }

                return (new LiveDecisions)->in($target);
            }
        });
    }

    it('refuses the decision inside the transaction', function (string $verb): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        closeOnFirstRead($release->requestApproval([$lead, $qa], ApprovalRule::Any));

        expect(fn () => match ($verb) {
            'approve' => $lead->approve($release),
            'reject' => $lead->reject($release),
            'ask' => Approvals::for($release)->as($lead)->ask(),
        })->toThrow(ClosedApprovalRequestException::class, 'is closed (approved)')
            ->and(Approval::query()->withTrashed()->count())->toBe(0);
    })->with(['approve', 'reject', 'ask']);

    it('refuses a toggle withdrawing the approval inside the transaction', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = $release->requestApproval([$lead, $qa], ApprovalRule::Unanimous);
        $approval = $lead->approve($release);

        closeOnFirstRead($request);

        expect(fn () => $lead->toggleApproval($release))->toThrow(ClosedApprovalRequestException::class)
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('refuses a withdrawal inside the transaction', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = $release->requestApproval([$lead, $qa], ApprovalRule::Unanimous);
        $approval = $lead->approve($release);

        // The round closes once cancel() has resolved it as open (its first read).
        $reads = 0;
        ApprovalRequest::retrieved(function (ApprovalRequest $retrieved) use ($request, &$reads): void {
            if ($reads++ === 0) {
                closeRound($request, ApprovalStatus::Rejected);
            }
        });

        expect(fn () => $lead->cancelApproval($release))->toThrow(ClosedApprovalRequestException::class, 'is closed (rejected)')
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });
});

describe('the outstanding asks of a round', function (): void {
    it('retires outstanding asks when the request resolves', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$a, $b, $c])->any()->open();

        $askB = Approvals::for($release)->as($b)->ask();
        $askC = Approvals::for($release)->as($c)->ask();

        Event::fake([ApprovalCancelled::class, ApprovalStatusChanged::class]);

        $a->approve($release);

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($release->pendingApprovals())->toHaveCount(0)
            ->and(Approvals::for($release)->hasPending())->toBeFalse()
            ->and(Blade::check('pendingApproval', $release))->toBeFalse()
            ->and($askB->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($askB->fresh()?->live)->toBeNull()
            ->and($askC->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($askC->fresh()?->live)->toBeNull();

        Event::assertDispatchedTimes(ApprovalCancelled::class, 2);
        Event::assertDispatched(
            ApprovalStatusChanged::class,
            fn (ApprovalStatusChanged $event): bool => $event->subject instanceof Approval
                && $event->subject->is($askB)
                && $event->from === ApprovalStatus::Pending
                && $event->to === ApprovalStatus::Cancelled,
        );
    });

    it('retires outstanding asks when the request lapses', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $release = ReleaseTestModel::create();
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$a, $b])->expiresIn(60)->open();
        $ask = Approvals::for($release)->as($b)->ask();

        CarbonImmutable::setTestNow('2026-09-28 13:00:00');

        expect(Approvals::expire())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($release->pendingApprovals())->toHaveCount(0);
    });

    it('leaves the decisions already made alone', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::request($release)->from([$a, $b])->open();

        $approval = $a->approve($release);
        $rejection = $b->reject($release);

        expect($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($rejection->fresh()?->status)->toBe(ApprovalStatus::Rejected)
            ->and(Approval::query()->live()->count())->toBe(2);
    });

    it('leaves a standalone ask on the same subject alone', function (): void {
        $deployment = DeploymentTestModel::create();
        $ask = Approvals::for($deployment)->as(ReviewerTestModel::create())->ask();

        $lead = ReviewerTestModel::create();
        Approvals::request($deployment)->from([$lead])->any()->open();
        $lead->approve($deployment);

        expect($ask->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });
});

it('carries the closed request', function (): void {
    $release = ReleaseTestModel::create();
    $lead = ReviewerTestModel::create();

    $request = closeRound($release->requestApproval([$lead]), ApprovalStatus::Cancelled);

    try {
        $lead->approve($release);
        $this->fail('Expected the decision to be refused.');
    } catch (ClosedApprovalRequestException $e) {
        expect($e)->toBeInstanceOf(ApprovalsException::class)
            ->and($e->request->is($request))->toBeTrue()
            ->and($e->getMessage())->toBe("Approval request [{$request->getKey()}] is closed (cancelled) and accepts no more decisions.");
    }
});
