<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Approvals\Actions\ReplaceStageApproversAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStageCleared;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\LiveDecisions;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Approvals\Tests\WeightedReviewerTestModel;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/*
|--------------------------------------------------------------------------
| Replacing the approvers of an open stage
|--------------------------------------------------------------------------
|
| `Approvals::for($subject)->within($round)->replaceApprovers($stage, $approvers)`
| hands the open stage to other approvers in place: the decisions already made stand,
| the round keeps its absolute expiry, ApprovalStageOpened announces the stage to its
| new approvers, and the round never resolves on the way.
|
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    LockRecorder::flush();
});

/**
 * A staged round on the release: "engineering" (open, unanimous by default) then
 * "product" (one approver).
 *
 * @param  list<ReviewerTestModel>  $engineering
 */
function replaceableRound(
    ReleaseTestModel $release,
    array $engineering,
    ApprovalRule $rule = ApprovalRule::Unanimous,
    ?int $quorum = null,
    ?int $required = null,
): ApprovalRequest {
    return Approvals::request($release)->stages([
        new StageDefinition($engineering, $rule, $quorum, 'engineering', $required),
        new StageDefinition([ReviewerTestModel::create()], ApprovalRule::Any, name: 'product'),
    ])->open();
}

/**
 * The stage's named approvers as `type#id` strings, in stored order.
 *
 * @return list<string>
 */
function namedOn(ApprovalRequestStage $stage): array
{
    return array_map(
        static fn ($approver): string => $approver->type.'#'.$approver->id,
        $stage->fresh()?->namedApprovers() ?? [],
    );
}

function nameOf(ReviewerTestModel $model): string
{
    return $model->getMorphClass().'#'.$model->getKey();
}

/**
 * Fake the package's own events: a refused replacement must announce nothing.
 */
function fakeReplaceEvents(): void
{
    Event::fake([
        ApprovalStageOpened::class,
        ApprovalStageCleared::class,
        ApprovalRequestResolved::class,
        ApprovalStatusChanged::class,
        ApprovalCancelled::class,
    ]);
}

function engineeringOf(ApprovalRequest $request): ApprovalRequestStage
{
    return $request->stages()->where('name', 'engineering')->firstOrFail();
}

describe('replacing the open stage', function (): void {
    it('replaces the pending approvers and keeps the decisions already made', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b, $c]);
        $approval = $a->approve($release);

        $returned = Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);
        $stage = engineeringOf($request);

        expect($returned)->toBeInstanceOf(ApprovalRequest::class)
            ->and($returned->is($request))->toBeTrue()
            ->and($returned->status)->toBe(ApprovalStatus::Pending)
            ->and(namedOn($stage))->toBe([nameOf($a), nameOf($m)])
            ->and($stage->fresh()?->required_approvers)->toBe(2)
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->fresh()?->live)->toBeTrue()
            ->and($release->approvalProgress()?->approved)->toBe(1)
            ->and($release->approvalProgress()?->required)->toBe(2);

        $m->approve($release);

        expect($stage->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($release->currentStage()?->name)->toBe('product');
    });

    it('lets only the new approvers decide the stage', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b]);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect(fn () => $b->approve($release))->toThrow(UnauthorizedApprovalException::class, 'is not a named approver')
            ->and(fn () => $a->approve($release))->toThrow(UnauthorizedApprovalException::class);

        $m->approve($release);

        expect(engineeringOf($request)->status)->toBe(ApprovalStatus::Approved);
    });

    it('keeps an explicit required count, so the new approvers fill the open slots', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m, $n] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b, $c], required: 2);
        $a->approve($release);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m, $n]);

        expect(engineeringOf($request)->required_approvers)->toBe(2)
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a), nameOf($m), nameOf($n)]);

        $n->approve($release);

        expect(engineeringOf($request)->status)->toBe(ApprovalStatus::Approved);
    });

    it('keeps the round\'s required total in step with its stages', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b, $c]);

        expect($request->fresh()?->required_approvers)->toBe(4);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect($request->fresh()?->required_approvers)->toBe(2);
    });

    it('keeps the round\'s absolute expiry and the stage\'s opening time', function (): void {
        CarbonImmutable::setTestNow('2026-11-08 09:00:00');

        $release = ReleaseTestModel::create();
        [$a, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)
            ->stages([new StageDefinition([$a], name: 'engineering')])
            ->expiringAt(CarbonImmutable::parse('2026-11-10 09:00:00'))
            ->open();

        CarbonImmutable::setTestNow('2026-11-09 15:30:00');

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect($request->fresh()?->expires_at?->toDateTimeString())->toBe('2026-11-10 09:00:00')
            ->and(engineeringOf($request)->opened_at?->toDateTimeString())->toBe('2026-11-08 09:00:00');
    });

    it('announces the stage again and never resolves the round', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b]);
        $a->approve($release);

        Event::fake();

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        Event::assertDispatchedTimes(ApprovalStageOpened::class, 1);
        Event::assertDispatched(
            ApprovalStageOpened::class,
            fn (ApprovalStageOpened $event): bool => $event->stage->is(engineeringOf($request))
                && in_array(nameOf($m), array_map(static fn ($approver): string => $approver->type.'#'.$approver->id, $event->stage->namedApprovers()), true),
        );
        Event::assertNotDispatched(ApprovalRequestResolved::class);
        Event::assertNotDispatched(ApprovalStageCleared::class);
        Event::assertNotDispatched(ApprovalStatusChanged::class);
    });

    it('retires the outstanding asks of the approvers it replaces', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b, $c]);
        $askB = Approvals::for($release)->as($b)->ask();
        $askC = Approvals::for($release)->as($c)->ask();

        Event::fake([ApprovalCancelled::class, ApprovalStatusChanged::class, ApprovalRequestResolved::class]);

        // c stays named: its ask is still the one it answers.
        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$c, $m]);

        expect($askB->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($askC->fresh()?->status)->toBe(ApprovalStatus::Pending);

        Event::assertDispatchedTimes(ApprovalCancelled::class, 1);
        Event::assertDispatched(ApprovalCancelled::class, fn (ApprovalCancelled $event): bool => $event->approval->is($askB));
        Event::assertDispatched(
            ApprovalStatusChanged::class,
            fn (ApprovalStatusChanged $event): bool => $event->subject instanceof Approval
                && $event->subject->is($askB)
                && $event->from === ApprovalStatus::Pending
                && $event->to === ApprovalStatus::Cancelled,
        );
        Event::assertNotDispatched(ApprovalRequestResolved::class);
    });

    it('replaces on the subject\'s open round when none is pinned', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a]);

        expect(Approvals::for($release)->replaceApprovers('engineering', [$m])->is($request))->toBeTrue()
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($m)]);
    });

    it('names each new approver once', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a]);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m, $m]);

        expect(namedOn(engineeringOf($request)))->toBe([nameOf($m)])
            ->and(engineeringOf($request)->required_approvers)->toBe(1);
    });

    it('names approvers on a stage opened without names', function (): void {
        $release = ReleaseTestModel::create();
        [$anyone, $m, $outsider] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->stages([
            new StageDefinition([], ApprovalRule::Unanimous, name: 'engineering', requiredApprovers: 2),
        ])->open();

        $anyone->approve($release);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect(namedOn(engineeringOf($request)))->toBe([nameOf($anyone), nameOf($m)])
            ->and(fn () => $outsider->approve($release))->toThrow(UnauthorizedApprovalException::class);

        $m->approve($release);

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('replaces on a round opened from a workflow preset', function (): void {
        config()->set('approvals.workflows.release', [
            'stages' => [
                ['rule' => ApprovalRule::Unanimous->value, 'required_approvers' => 1, 'name' => 'engineering'],
                ['rule' => ApprovalRule::Any->value, 'required_approvers' => 1, 'name' => 'product'],
            ],
        ]);

        $release = ReleaseTestModel::create();
        [$a, $p, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->workflow('release')->open([[$a], [$p]]);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect($request->fresh()?->workflow)->toBe('release')
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($m)]);
    });
});

describe('weights', function (): void {
    it('weighs each new approver on the subject', function (): void {
        $release = ReleaseTestModel::create();
        $a = WeightedReviewerTestModel::create();
        $b = WeightedReviewerTestModel::create();
        $m = WeightedReviewerTestModel::create();
        $b->weight = 2;
        $m->weight = 3;

        $request = Approvals::request($release)->stages([
            new StageDefinition([$a, $b], ApprovalRule::Weighted, 3, 'engineering'),
        ])->open();

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect(array_map(static fn ($approver): int => $approver->weight, engineeringOf($request)->namedApprovers()))->toBe([3]);

        Approvals::for($release)->as($m)->approve();

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('refuses approvers who could never reach the stage\'s threshold', function (): void {
        $release = ReleaseTestModel::create();
        $a = WeightedReviewerTestModel::create();
        $b = WeightedReviewerTestModel::create();
        $m = WeightedReviewerTestModel::create();
        $b->weight = 2;

        $request = Approvals::request($release)->stages([
            new StageDefinition([$a, $b], ApprovalRule::Weighted, 3, 'engineering'),
        ])->open();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]))
            ->toThrow(InvalidApprovalRequestException::class, 'threshold of 3 can never be met');
    });
});

describe('refusals', function (): void {
    it('refuses an empty approver list: a stage without names would let anyone decide', function (): void {
        $release = ReleaseTestModel::create();
        $a = ReviewerTestModel::create();

        $request = replaceableRound($release, [$a]);

        fakeReplaceEvents();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', []))
            ->toThrow(InvalidApprovalRequestException::class, 'needs at least one approver')
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a)]);

        Event::assertNothingDispatched();
    });

    it('refuses a closed round', function (ApprovalStatus $status): void {
        $release = ReleaseTestModel::create();
        [$a, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a]);
        $request->newQuery()->whereKey($request->getKey())->update(['status' => $status->value]);

        fakeReplaceEvents();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]))
            ->toThrow(ClosedApprovalRequestException::class, 'is closed ('.$status->value.')')
            ->and(fn () => Approvals::for($release)->replaceApprovers('engineering', [$m]))
            ->toThrow(ClosedApprovalRequestException::class)
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a)]);

        Event::assertNothingDispatched();
    })->with([ApprovalStatus::Approved, ApprovalStatus::Rejected, ApprovalStatus::Cancelled, ApprovalStatus::Expired]);

    it('refuses a round past its expiry without resolving it', function (): void {
        CarbonImmutable::setTestNow('2026-11-08 09:00:00');

        $release = ReleaseTestModel::create();
        [$a, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)
            ->stages([new StageDefinition([$a], name: 'engineering')])
            ->expiresIn(3600)
            ->open();

        CarbonImmutable::setTestNow('2026-11-08 11:00:00');

        fakeReplaceEvents();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]))
            ->toThrow(ClosedApprovalRequestException::class, 'is past its expiry')
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a)]);

        Event::assertNothingDispatched();
    });

    it('refuses a stage the round does not have', function (): void {
        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('legal', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'has no stage [legal]');
    });

    it('refuses a stage that already settled', function (): void {
        $release = ReleaseTestModel::create();
        $a = ReviewerTestModel::create();

        $request = replaceableRound($release, [$a]);
        $a->approve($release);

        fakeReplaceEvents();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'has already settled (approved)')
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a)]);

        Event::assertNothingDispatched();
    });

    it('refuses a stage that has not opened yet', function (): void {
        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('product', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'has not opened yet');
    });

    it('refuses a flat round', function (): void {
        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'is not staged');
    });

    it('refuses an approver who already decided the stage', function (ApprovalStatus $decision): void {
        $release = ReleaseTestModel::create();
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        if ($decision === ApprovalStatus::Approved) {
            $request = replaceableRound($release, [$a, $b]);
            $a->approve($release);
        } else {
            // One approval clears it, so a's rejection leaves the stage open on b.
            $request = replaceableRound($release, [$a, $b], ApprovalRule::Quorum, quorum: 1);
            $a->reject($release);
        }

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [ReviewerTestModel::create(), $a]))
            ->toThrow(InvalidApprovalRequestException::class, 'has already decided')
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a), nameOf($b)]);
    })->with([ApprovalStatus::Approved, ApprovalStatus::Rejected]);

    it('refuses an approver who approved through a delegate', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $b, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$boss, $b]);
        $boss->delegateApprovalsTo($deputy);
        $deputy->approve($release);

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [$boss]))
            ->toThrow(InvalidApprovalRequestException::class, 'has already decided');
    });

    it('refuses approvers that would settle the stage at once', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        // Two of three must approve; a rejected, so b and c are both still needed.
        $request = replaceableRound($release, [$a, $b, $c], ApprovalRule::Quorum, quorum: 2);
        $a->reject($release);

        fakeReplaceEvents();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]))
            ->toThrow(InvalidApprovalRequestException::class, 'would settle')
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($a), nameOf($b), nameOf($c)])
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(engineeringOf($request)->status)->toBe(ApprovalStatus::Pending);

        Event::assertNothingDispatched();
    });

    it('refuses an unsaved approver', function (): void {
        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [new ReviewerTestModel]))
            ->toThrow(InvalidApprovalRequestException::class, 'must be saved');
    });

    it('refuses a round of another subject', function (): void {
        $release = ReleaseTestModel::create();
        $other = ReleaseTestModel::create();
        $request = replaceableRound($other, [ReviewerTestModel::create()]);

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'does not belong to the approvable');
    });

    it('refuses a pinned round that was deleted', function (): void {
        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);
        $request->delete();

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'has no approval round');
    });

    it('refuses a subject that never had a round', function (): void {
        expect(fn () => Approvals::for(ReleaseTestModel::create())->replaceApprovers('engineering', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'has no approval round');
    });

    it('refuses decision settings and an actor', function (string $setting): void {
        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        $pending = Approvals::for($release)->within($request);

        match ($setting) {
            'as()' => $pending->as(ReviewerTestModel::create()),
            'because()' => $pending->because('vacation'),
            'weight()' => $pending->weight(2),
            'expiresIn() / expiringAt()' => $pending->expiresIn(60),
        };

        expect(fn () => $pending->replaceApprovers('engineering', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, '['.$setting.']');
    })->with(['as()', 'because()', 'weight()', 'expiresIn() / expiringAt()']);
});

describe('delegation', function (): void {
    it('keeps a decision a delegate made for a named approver', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $b, $deputy, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$boss, $b]);
        $boss->delegateApprovalsTo($deputy);
        $delegated = $deputy->approve($release);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect(namedOn(engineeringOf($request)))->toBe([nameOf($boss), nameOf($m)])
            ->and($delegated->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($delegated->fresh()?->decided_by_id)->toBe($deputy->getKey());

        $m->approve($release);

        expect(engineeringOf($request)->status)->toBe(ApprovalStatus::Approved);
    });

    it('stops the delegate of a replaced approver from deciding', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $deputy, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b]);
        $b->delegateApprovalsTo($deputy);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$a, $m]);

        expect(fn () => $deputy->approve($release))->toThrow(UnauthorizedApprovalException::class);
    });

    it('lets the delegate of a new approver decide for it', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $m, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a]);
        $m->delegateApprovalsTo($deputy);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        $approval = $deputy->approve($release);

        expect($approval->actor_id)->toBe($m->getKey())
            ->and($approval->decided_by_id)->toBe($deputy->getKey())
            ->and(engineeringOf($request)->status)->toBe(ApprovalStatus::Approved);
    });
});

describe('concurrency', function (): void {
    /**
     * Replays a replacement landing after a decision resolved its target (with the old
     * approvers), before the decision's transaction takes the request lock.
     *
     * @param  list<ReviewerTestModel>  $approvers
     */
    function replaceOnFirstRead(ReleaseTestModel $release, ApprovalRequest $request, array $approvers): void
    {
        app()->instance(LiveDecisions::class, new class($release, $request, $approvers)
        {
            private bool $replaced = false;

            /**
             * @param  list<ReviewerTestModel>  $approvers
             */
            public function __construct(
                private readonly ReleaseTestModel $release,
                private readonly ApprovalRequest $request,
                private readonly array $approvers,
            ) {}

            public function in(DecisionTarget $target): ?Approval
            {
                if (! $this->replaced) {
                    $this->replaced = true;
                    Approvals::for($this->release)->within($this->request)->replaceApprovers('engineering', $this->approvers);
                }

                return (new LiveDecisions)->in($target);
            }
        });
    }

    it('refuses a decision by an approver replaced between its read and its write', function (string $verb): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b]);

        replaceOnFirstRead($release, $request, [$a, $m]);

        expect(fn () => match ($verb) {
            'approve' => $b->approve($release),
            'reject' => $b->reject($release),
            'ask' => Approvals::for($release)->as($b)->ask(),
        })->toThrow(UnauthorizedApprovalException::class, 'is not a named approver')
            ->and(Approval::query()->withTrashed()->whereMorphedTo('actor', $b)->count())->toBe(0)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(engineeringOf($request)->status)->toBe(ApprovalStatus::Pending);
    })->with(['approve', 'reject', 'ask']);

    it('still records a decision by an approver the replacement keeps', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a, $b]);

        replaceOnFirstRead($release, $request, [$a, $m]);

        expect($a->approve($release)->status)->toBe(ApprovalStatus::Approved)
            ->and(engineeringOf($request)->status)->toBe(ApprovalStatus::Pending);
    });

    it('takes the round\'s row lock inside its own transaction', function (): void {
        $connection = DB::connection();

        if ($connection->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The lock-recording grammar is SQLite\'s; a real engine takes the real lock.');
        }

        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        $connection->setQueryGrammar(new LockRecordingGrammar($connection));
        LockRecorder::flush();
        LockRecorder::listenForMarkers();

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [ReviewerTestModel::create()]);

        $locks = array_values(array_filter(
            LockRecorder::recorded(),
            static fn (array $lock): bool => str_contains($lock['sql'], 'approval_requests'),
        ));

        expect($locks)->not->toBeEmpty()
            ->and($locks[0]['transactionDepth'])->toBeGreaterThanOrEqual(1);
    });
});

describe('authorization', function (): void {
    it('runs no decision gate itself, and the new approvers still pass the gate to decide', function (): void {
        config()->set('approvals.authorization.enabled', true);
        Gate::define('decide-approval', fn (): bool => false);

        $release = ReleaseTestModel::create();
        [$a, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = replaceableRound($release, [$a]);

        Approvals::for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect(namedOn(engineeringOf($request)))->toBe([nameOf($m)])
            ->and(fn () => $m->approve($release))->toThrow(UnauthorizedApprovalException::class, 'not authorized');
    });
});

describe('every way in', function (): void {
    it('replaces through an injected manager', function (): void {
        $release = ReleaseTestModel::create();
        $m = ReviewerTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        app(ApprovalsManager::class)->for($release)->within($request)->replaceApprovers('engineering', [$m]);

        expect(namedOn(engineeringOf($request)))->toBe([nameOf($m)]);
    });

    it('replaces through the raw action', function (): void {
        $release = ReleaseTestModel::create();
        $m = ReviewerTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        $round = app(ReplaceStageApproversAction::class)->execute($release, 'engineering', [$m], $request);

        expect($round->is($request))->toBeTrue()
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($m)]);
    });
});

describe('the fake', function (): void {
    it('records a replacement and asserts on it', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        $other = ReleaseTestModel::create();
        $m = ReviewerTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        Approvals::assertNothingReplaced();

        app(ApprovalsManager::class)->for($release)->within($request)->replaceApprovers('engineering', [$m]);

        $recorded = $fake->recorded(ApprovalOperation::Replace);

        expect($recorded)->toHaveCount(1)
            ->and($recorded[0]->context['stage'])->toBe('engineering')
            ->and($recorded[0]->context['approvers'])->toBe([$m])
            ->and($recorded[0]->involves('subject', $release))->toBeTrue()
            ->and($recorded[0]->result)->toBeInstanceOf(ApprovalRequest::class)
            ->and(namedOn(engineeringOf($request)))->toBe([nameOf($m)]);

        Approvals::assertApproversReplaced($release);
        Approvals::assertApproversReplaced($release, 'engineering');

        expect(fn () => Approvals::assertApproversReplaced($other))->toThrow(AssertionFailedError::class, 'Expected the approvers of a stage')
            ->and(fn () => Approvals::assertApproversReplaced($release, 'product'))->toThrow(AssertionFailedError::class, '[product]')
            ->and(fn () => Approvals::assertNothingReplaced())->toThrow(AssertionFailedError::class, '1 replacement(s)');
    });

    it('records nothing for a refused replacement', function (): void {
        Approvals::fake();

        $release = ReleaseTestModel::create();
        $request = replaceableRound($release, [ReviewerTestModel::create()]);

        expect(fn () => Approvals::for($release)->within($request)->replaceApprovers('engineering', []))
            ->toThrow(InvalidApprovalRequestException::class);

        Approvals::assertNothingReplaced();
    });
});
