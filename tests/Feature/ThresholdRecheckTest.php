<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Approvals\Actions\RecheckApprovalThresholdAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\DataTransferObjects\ThresholdVerdict;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalThresholdUnreachable;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Tests\Fixtures\AuthorExclusionResolver;
use RoundlyConsulting\Approvals\Tests\Fixtures\InvoiceTestModel;
use RoundlyConsulting\Approvals\Tests\Fixtures\MemberTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Approvals\Tests\WeightedReviewerTestModel;

/*
|--------------------------------------------------------------------------
| Re-checking that a round can still reach its threshold
|--------------------------------------------------------------------------
|
| `Approvals::for($subject)->recheckThreshold(?$closeAs)` tells whether the decisions in,
| plus those the approvers who can still decide could add, can still approve the round —
| and, with `$closeAs`, voids (cancelled) or fails (rejected) one that cannot.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

describe('the verdict', function (): void {
    it('reads a round whose approvers can all still decide as reachable', function (): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([MemberTestModel::create(), MemberTestModel::create()])->open();

        $verdict = Approvals::for($release)->recheckThreshold();

        expect($verdict)->toBeInstanceOf(ThresholdVerdict::class)
            ->and($verdict?->request->is($round))->toBeTrue()
            ->and($verdict?->reachable)->toBeTrue()
            ->and($verdict?->unavailable)->toBe([])
            ->and($verdict?->stage)->toBeNull()
            ->and($verdict?->closedAs)->toBeNull();
    });

    it('reads a unanimous round with a deleted approver as unreachable, and leaves it open', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $gone] = [MemberTestModel::create(), MemberTestModel::create()];

        $round = Approvals::request($release)->from([$a, $gone])->open();
        $gone->delete();

        Event::fake([ApprovalThresholdUnreachable::class, ApprovalRequestResolved::class]);

        $verdict = Approvals::for($release)->recheckThreshold();

        expect($verdict?->reachable)->toBeFalse()
            ->and($verdict?->unavailable)->toHaveCount(1)
            ->and($verdict?->unavailable[0]->matches($gone))->toBeTrue()
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);

        Event::assertNotDispatched(ApprovalThresholdUnreachable::class);
        Event::assertNotDispatched(ApprovalRequestResolved::class);
    });

    it('reads a quorum round as reachable while enough approvers remain', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $gone] = [MemberTestModel::create(), MemberTestModel::create(), MemberTestModel::create()];

        Approvals::request($release)->from([$a, $b, $gone])->quorum(2)->open();
        $gone->delete();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();

        $b->delete();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeFalse();
    });

    it('counts the decisions already in', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $gone] = [MemberTestModel::create(), MemberTestModel::create(), MemberTestModel::create()];

        Approvals::request($release)->from([$a, $b, $gone])->quorum(2)->open();
        $a->approve($release);
        $b->delete();

        // One approval in, one approver left who can add the second.
        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();

        $gone->delete();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeFalse();

        $b->restore();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();
    });

    it('weighs the approvers who can still decide', function (): void {
        $release = ReleaseTestModel::create();
        $heavy = WeightedReviewerTestModel::create();
        $light = WeightedReviewerTestModel::create();
        $heavy->weight = 3;

        $round = Approvals::request($release)->from([$heavy, $light])->weighted(3)->open();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();

        // The stored row gone: only the light approver remains, and it carries 1 of 3.
        $round->forceFill(['approvers' => [
            ['type' => $heavy->getMorphClass(), 'id' => 999_999, 'weight' => 3],
            ['type' => $light->getMorphClass(), 'id' => $light->getKey(), 'weight' => 1],
        ]])->save();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeFalse();
    });

    it('reads an any round as reachable while one approver remains', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b] = [MemberTestModel::create(), MemberTestModel::create()];

        Approvals::request($release)->from([$a, $b])->any()->open();
        $a->delete();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();

        $b->delete();

        expect(Approvals::for($release)->recheckThreshold()?->unavailable)->toHaveCount(2);
    });

    it('reads an approver whose type no longer resolves to a model as unavailable', function (): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([MemberTestModel::create()])->open();

        $round->forceFill(['approvers' => [['type' => 'App\\Models\\Gone', 'id' => 1, 'weight' => 1]]])->save();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeFalse();
    });

    it('reads an excluded approver as unavailable', function (): void {
        config()->set('approvals.exclusions.resolver', AuthorExclusionResolver::class);

        $author = ReviewerTestModel::create();
        $invoice = InvoiceTestModel::create();

        Approvals::request($invoice)->from([$author])->open();
        $invoice->update(['author_id' => $author->getKey()]);

        expect(Approvals::for($invoice)->recheckThreshold()?->reachable)->toBeFalse();
    });

    it('reads an approver the gate refuses as unavailable, unless a delegate can decide for it', function (): void {
        config()->set('approvals.authorization.enabled', true);

        [$blocked, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Gate::define('decide-approval', fn (ReviewerTestModel $user): bool => ! $user->is($blocked));

        $release = ReleaseTestModel::create();
        Approvals::request($release)->from([$blocked])->open();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeFalse();

        Approvals::delegations($blocked)->to($deputy)->grant();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();
    });

    it('reads a delegate who is excluded as no help', function (): void {
        config()->set('approvals.authorization.enabled', true);
        config()->set('approvals.exclusions.resolver', AuthorExclusionResolver::class);

        [$blocked, $author] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Gate::define('decide-approval', fn (ReviewerTestModel $user): bool => ! $user->is($blocked));

        $invoice = InvoiceTestModel::create(['author_id' => $author->getKey()]);
        Approvals::request($invoice)->from([$blocked])->open();
        Approvals::delegations($blocked)->to($author)->grant();

        expect(Approvals::for($invoice)->recheckThreshold()?->reachable)->toBeFalse();
    });

    it('reads a round without names as reachable: anyone may decide it', function (): void {
        $release = ReleaseTestModel::create();
        Approvals::request($release)->quorum(2)->open();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();
    });

    it('returns null without an open round', function (): void {
        CarbonImmutable::setTestNow('2026-11-09 09:00:00');

        $none = ReleaseTestModel::create();

        $closed = ReleaseTestModel::create();
        $closedRound = Approvals::request($closed)->from([MemberTestModel::create()])->open();
        Approvals::for($closed)->close();

        $overdue = ReleaseTestModel::create();
        $overdueRound = Approvals::request($overdue)->from([MemberTestModel::create()])->expiresIn(60)->open();

        CarbonImmutable::setTestNow('2026-11-09 10:00:00');

        expect(Approvals::for($none)->recheckThreshold())->toBeNull()
            ->and(Approvals::for($closed)->within($closedRound)->recheckThreshold())->toBeNull()
            ->and(Approvals::for($closed)->recheckThreshold())->toBeNull()
            ->and(Approvals::for($overdue)->recheckThreshold(ApprovalStatus::Cancelled))->toBeNull()
            ->and($overdueRound->fresh()?->status)->toBe(ApprovalStatus::Expired);
    });
});

describe('staged rounds', function (): void {
    it('reads a round whose later stage can no longer clear as unreachable, naming the stage', function (): void {
        $release = ReleaseTestModel::create();
        [$eng, $gone] = [MemberTestModel::create(), MemberTestModel::create()];

        $round = Approvals::request($release)->stages([
            new StageDefinition([$eng], name: 'engineering'),
            new StageDefinition([$gone], name: 'product'),
        ])->open();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();

        $gone->delete();

        $verdict = Approvals::for($release)->recheckThreshold();

        expect($verdict?->reachable)->toBeFalse()
            ->and($verdict?->stage?->name)->toBe('product')
            ->and($verdict?->stage?->approval_request_id)->toBe($round->getKey());
    });

    it('reads a round that continues past rejections as reachable while one stage can still clear', function (): void {
        $release = ReleaseTestModel::create();
        [$eng, $gone] = [MemberTestModel::create(), MemberTestModel::create()];

        Approvals::request($release)->stages([
            new StageDefinition([$eng], name: 'engineering'),
            new StageDefinition([$gone], name: 'product'),
        ])->continueOnRejection()->open();

        $gone->delete();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();

        $eng->delete();

        $verdict = Approvals::for($release)->recheckThreshold();

        expect($verdict?->reachable)->toBeFalse()
            ->and($verdict?->stage?->name)->toBe('engineering')
            ->and($verdict?->unavailable)->toHaveCount(2);
    });

    it('reads a round that continues past rejections as reachable once a stage cleared', function (): void {
        $release = ReleaseTestModel::create();
        [$eng, $gone] = [MemberTestModel::create(), MemberTestModel::create()];

        Approvals::request($release)->stages([
            new StageDefinition([$eng], name: 'engineering'),
            new StageDefinition([$gone], name: 'product'),
        ])->continueOnRejection()->open();

        $eng->approve($release);
        $gone->delete();

        expect(Approvals::for($release)->recheckThreshold()?->reachable)->toBeTrue();
    });
});

describe('closing an unreachable round', function (): void {
    it('voids it as cancelled and announces it', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $gone] = [MemberTestModel::create(), MemberTestModel::create()];

        $round = Approvals::request($release)->from([$a, $gone])->open();
        $ask = Approvals::for($release)->as($a)->ask();
        $gone->delete();

        Event::fake([ApprovalThresholdUnreachable::class, ApprovalRequestResolved::class, ApprovalCancelled::class]);

        $verdict = Approvals::for($release)->recheckThreshold(ApprovalStatus::Cancelled);

        expect($verdict?->closedAs)->toBe(ApprovalStatus::Cancelled)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled);

        Event::assertDispatchedTimes(ApprovalRequestResolved::class, 1);
        Event::assertDispatched(ApprovalThresholdUnreachable::class, fn (ApprovalThresholdUnreachable $event): bool => $event->verdict->request->is($round)
            && $event->verdict->closedAs === ApprovalStatus::Cancelled
            && $event->verdict->request->status === ApprovalStatus::Cancelled);
        Event::assertDispatched(ApprovalCancelled::class, fn (ApprovalCancelled $event): bool => $event->approval->is($ask));
    });

    it('fails it as rejected', function (): void {
        $release = ReleaseTestModel::create();
        $gone = MemberTestModel::create();

        $round = Approvals::request($release)->from([$gone])->open();
        $gone->delete();

        expect(Approvals::for($release)->recheckThreshold(ApprovalStatus::Rejected)?->closedAs)->toBe(ApprovalStatus::Rejected)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Rejected)
            ->and(Approvals::status($release))->toBe(ApprovalStatus::Rejected);
    });

    it('leaves a reachable round open', function (): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([MemberTestModel::create()])->open();

        Event::fake([ApprovalThresholdUnreachable::class]);

        expect(Approvals::for($release)->recheckThreshold(ApprovalStatus::Rejected)?->closedAs)->toBeNull()
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);

        Event::assertNotDispatched(ApprovalThresholdUnreachable::class);
    });

    it('refuses any other outcome', function (ApprovalStatus $outcome): void {
        $release = ReleaseTestModel::create();
        Approvals::request($release)->from([MemberTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->recheckThreshold($outcome))
            ->toThrow(InvalidStatusTransitionException::class, 'can only be closed as [cancelled] or [rejected]');
    })->with([ApprovalStatus::Approved, ApprovalStatus::Pending, ApprovalStatus::Expired]);

    it('re-checks only the pinned round', function (): void {
        $release = ReleaseTestModel::create();
        $gone = MemberTestModel::create();

        $older = Approvals::request($release)->from([$gone])->open();
        $newer = Approvals::request($release)->from([MemberTestModel::create()])->open();
        $gone->delete();

        expect(Approvals::for($release)->recheckThreshold()?->request->is($newer))->toBeTrue()
            ->and(Approvals::for($release)->within($older)->recheckThreshold(ApprovalStatus::Cancelled)?->closedAs)->toBe(ApprovalStatus::Cancelled)
            ->and($newer->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });
});

describe('refusals', function (): void {
    it('refuses a round of another subject', function (): void {
        $foreign = Approvals::request(ReleaseTestModel::create())->from([MemberTestModel::create()])->open();

        expect(fn () => Approvals::for(ReleaseTestModel::create())->within($foreign)->recheckThreshold())
            ->toThrow(InvalidApprovalRequestException::class, 'does not belong to the approvable');
    });

    it('refuses an actor and decision settings', function (string $setting): void {
        $pending = Approvals::for(ReleaseTestModel::create());

        match ($setting) {
            'as()' => $pending->as(ReviewerTestModel::create()),
            'expiresIn() / expiringAt()' => $pending->expiresIn(60),
        };

        expect(fn () => $pending->recheckThreshold())
            ->toThrow(InvalidApprovalRequestException::class, "recheckThreshold() involves no actor and records no decision, so [{$setting}]");
    })->with(['as()', 'expiresIn() / expiringAt()']);
});

describe('every way in', function (): void {
    it('re-checks through the raw action', function (): void {
        $release = ReleaseTestModel::create();
        $gone = MemberTestModel::create();
        Approvals::request($release)->from([$gone])->open();
        $gone->delete();

        expect(app(RecheckApprovalThresholdAction::class)->execute($release)?->reachable)->toBeFalse();
    });

    it('records re-checks in the fake and asserts on them', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        $other = ReleaseTestModel::create();
        $gone = MemberTestModel::create();
        Approvals::request($release)->from([$gone])->open();

        Approvals::assertNothingRechecked();

        app(ApprovalsManager::class)->for($release)->recheckThreshold();

        expect($fake->recorded(ApprovalOperation::Recheck))->toHaveCount(1);

        Approvals::assertRechecked($release);

        expect(fn () => Approvals::assertRechecked($release, ApprovalStatus::Cancelled))
            ->toThrow(AssertionFailedError::class, 'as [cancelled]')
            ->and(fn () => Approvals::assertRechecked($other))->toThrow(AssertionFailedError::class, 'to be re-checked')
            ->and(fn () => Approvals::assertNothingRechecked())->toThrow(AssertionFailedError::class, '1 re-check(s)');

        $gone->delete();
        Approvals::for($release)->recheckThreshold(ApprovalStatus::Cancelled);

        Approvals::assertRechecked($release, ApprovalStatus::Cancelled);
    });
});
