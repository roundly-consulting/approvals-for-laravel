<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Approvals\Actions\ReplaceRequestApproversAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproversReplaced;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Tests\Fixtures\AuthorExclusionResolver;
use RoundlyConsulting\Approvals\Tests\Fixtures\InvoiceTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Approvals\Tests\WeightedReviewerTestModel;

/*
|--------------------------------------------------------------------------
| Replacing the approvers of a flat round
|--------------------------------------------------------------------------
|
| `Approvals::for($subject)->within($round)->replaceFlatApprovers($approvers)` hands a
| round without stages to other approvers in place, the way replaceApprovers() does a
| stage: the decisions already made stand, the round keeps its expiry, and it never
| resolves on the way.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

/**
 * The round's named approvers as `type#id` strings, in stored order.
 *
 * @return list<string>
 */
function flatNamedOn(ApprovalRequest $round): array
{
    return array_map(
        static fn (NamedApprover $approver): string => $approver->type.'#'.$approver->id,
        $round->fresh()?->namedApprovers() ?? [],
    );
}

function flatNameOf(ReviewerTestModel $model): string
{
    return $model->getMorphClass().'#'.$model->getKey();
}

describe('replacing a flat round', function (): void {
    it('replaces the pending approvers and keeps the decisions already made', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($release)->from([$a, $b, $c])->open();
        $approval = $a->approve($release);

        $returned = Approvals::for($release)->within($round)->replaceFlatApprovers([$m]);

        expect($returned->is($round))->toBeTrue()
            ->and($returned->status)->toBe(ApprovalStatus::Pending)
            ->and(flatNamedOn($round))->toBe([flatNameOf($a), flatNameOf($m)])
            ->and($round->fresh()?->required_approvers)->toBe(2)
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and(fn () => $b->approve($release))->toThrow(UnauthorizedApprovalException::class, 'is not a named approver');

        $m->approve($release);

        expect(Approvals::status($release))->toBe(ApprovalStatus::Approved);
    });

    it('keeps an explicit required count, the rule and the expiry', function (): void {
        CarbonImmutable::setTestNow('2026-11-09 09:00:00');

        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m, $n] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($release)->from([$a, $b, $c])->quorum(2)
            ->expiringAt(CarbonImmutable::parse('2026-11-10 09:00:00'))->open();
        $a->approve($release);

        Approvals::for($release)->replaceFlatApprovers([$m, $n]);

        expect($round->fresh()?->rule)->toBe(ApprovalRule::Quorum)
            ->and($round->fresh()?->quorum)->toBe(2)
            ->and($round->fresh()?->required_approvers)->toBe(3)
            ->and($round->fresh()?->expires_at?->toDateTimeString())->toBe('2026-11-10 09:00:00');

        $n->approve($release);

        expect(Approvals::status($release))->toBe(ApprovalStatus::Approved);
    });

    it('names approvers on an unnamed round, keeping who decided', function (): void {
        $release = ReleaseTestModel::create();
        [$early, $m, $outsider] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = app(ApprovalsManager::class)->request($release)->any()->open();

        $early->reject($release);

        Approvals::for($release)->replaceFlatApprovers([$m]);

        expect(flatNamedOn($round))->toBe([flatNameOf($early), flatNameOf($m)])
            ->and(fn () => $outsider->approve($release))->toThrow(UnauthorizedApprovalException::class);
    });

    it('weighs each new approver on the subject', function (): void {
        $release = ReleaseTestModel::create();
        $a = WeightedReviewerTestModel::create();
        $m = WeightedReviewerTestModel::create();
        $a->weight = 3;
        $m->weight = 3;

        $round = Approvals::request($release)->from([$a])->weighted(3)->open();

        Approvals::for($release)->replaceFlatApprovers([$m]);

        expect(array_map(static fn (NamedApprover $approver): int => $approver->weight, $round->fresh()?->namedApprovers() ?? []))->toBe([3]);

        Approvals::for($release)->as($m)->approve();

        expect($round->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('retires the replaced approvers\' asks and announces who joined and who left', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($release)->from([$a, $b, $c])->open();
        $a->approve($release);
        $askB = Approvals::for($release)->as($b)->ask();
        $askC = Approvals::for($release)->as($c)->ask();

        Event::fake([ApprovalApproversReplaced::class, ApprovalCancelled::class, ApprovalStatusChanged::class, ApprovalRequestResolved::class, ApprovalStageOpened::class]);

        Approvals::for($release)->replaceFlatApprovers([$c, $m]);

        expect($askB->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($askC->fresh()?->status)->toBe(ApprovalStatus::Pending);

        Event::assertDispatchedTimes(ApprovalCancelled::class, 1);
        Event::assertDispatched(ApprovalApproversReplaced::class, fn (ApprovalApproversReplaced $event): bool => $event->request->is($round)
            && $event->stage === null
            && count($event->added) === 1 && $event->added[0]->matches($m)
            && count($event->removed) === 1 && $event->removed[0]->matches($b));
        Event::assertNotDispatched(ApprovalRequestResolved::class);
        Event::assertNotDispatched(ApprovalStageOpened::class);
    });
});

describe('refusals', function (): void {
    it('refuses an empty list', function (): void {
        $release = ReleaseTestModel::create();
        Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->replaceFlatApprovers([]))
            ->toThrow(InvalidApprovalRequestException::class, 'needs at least one approver');
    });

    it('refuses a staged round, pointing at replaceApprovers()', function (): void {
        $release = ReleaseTestModel::create();
        Approvals::request($release)->stages([new StageDefinition([ReviewerTestModel::create()], name: 'one')])->open();

        expect(fn () => Approvals::for($release)->replaceFlatApprovers([ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'replaceApprovers($stage, $approvers)');
    });

    it('points replaceApprovers() on a flat round at replaceFlatApprovers()', function (): void {
        $release = ReleaseTestModel::create();
        Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->replaceApprovers('one', [ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'Use replaceFlatApprovers()');
    });

    it('refuses a closed round, an overdue round and a subject without a round', function (): void {
        CarbonImmutable::setTestNow('2026-11-09 09:00:00');

        $closed = ReleaseTestModel::create();
        $a = ReviewerTestModel::create();
        Approvals::request($closed)->from([$a])->open();
        $a->approve($closed);

        $overdue = ReleaseTestModel::create();
        $overdueRound = Approvals::request($overdue)->from([ReviewerTestModel::create()])->expiresIn(60)->open();

        CarbonImmutable::setTestNow('2026-11-09 10:00:00');

        expect(fn () => Approvals::for($closed)->replaceFlatApprovers([ReviewerTestModel::create()]))
            ->toThrow(ClosedApprovalRequestException::class)
            ->and(fn () => Approvals::for($overdue)->replaceFlatApprovers([ReviewerTestModel::create()]))
            ->toThrow(ClosedApprovalRequestException::class, 'past its expiry')
            ->and($overdueRound->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(fn () => Approvals::for(ReleaseTestModel::create())->replaceFlatApprovers([ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'has no approval round');
    });

    it('refuses an approver who already decided', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
        Approvals::request($release)->from([$a, $b])->open();
        $a->approve($release);

        expect(fn () => Approvals::for($release)->replaceFlatApprovers([$a]))
            ->toThrow(InvalidApprovalRequestException::class, 'has already decided approval request');
    });

    it('refuses approvers that would settle the round at once', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        // Two of three needed, one approved: with an explicit count of one the decisions in settle it.
        $round = app(ApprovalsManager::class)->request($release)->from([$a, $b])->quorum(2)->open();
        $a->approve($release);
        $round->forceFill(['quorum' => 1])->save();

        expect(fn () => Approvals::for($release)->replaceFlatApprovers([$m]))
            ->toThrow(InvalidApprovalRequestException::class, 'would settle approval request')
            ->and(flatNamedOn($round))->toBe([flatNameOf($a), flatNameOf($b)]);
    });

    it('refuses approvers who could never reach the threshold', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::request($release)->from([$a, $b, $c])->quorum(3)->open();

        expect(fn () => Approvals::for($release)->replaceFlatApprovers([$m]))
            ->toThrow(InvalidApprovalRequestException::class, 'can never be met');
    });

    it('refuses an excluded approver', function (): void {
        config()->set('approvals.exclusions.resolver', AuthorExclusionResolver::class);

        $author = ReviewerTestModel::create();
        $invoice = InvoiceTestModel::create(['author_id' => $author->getKey()]);
        $requester = ReviewerTestModel::create();

        Approvals::request($invoice)->excluding([$requester])->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($invoice)->replaceFlatApprovers([$author]))
            ->toThrow(InvalidApprovalRequestException::class, 'is excluded from deciding')
            ->and(fn () => Approvals::for($invoice)->replaceFlatApprovers([$requester]))
            ->toThrow(InvalidApprovalRequestException::class, 'is excluded from deciding');
    });

    it('refuses a round of another subject', function (): void {
        $foreign = Approvals::request(ReleaseTestModel::create())->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for(ReleaseTestModel::create())->within($foreign)->replaceFlatApprovers([ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, 'does not belong to the approvable');
    });

    it('refuses an actor and decision settings', function (string $setting): void {
        $pending = Approvals::for(ReleaseTestModel::create());

        match ($setting) {
            'as()' => $pending->as(ReviewerTestModel::create()),
            'because()' => $pending->because('why'),
            'weight()' => $pending->weight(2),
        };

        expect(fn () => $pending->replaceFlatApprovers([ReviewerTestModel::create()]))
            ->toThrow(InvalidApprovalRequestException::class, "replaceFlatApprovers() involves no actor and records no decision, so [{$setting}]");
    })->with(['as()', 'because()', 'weight()']);
});

describe('every way in', function (): void {
    it('replaces through the raw action', function (): void {
        $release = ReleaseTestModel::create();
        $m = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(app(ReplaceRequestApproversAction::class)->execute($release, [$m], $round)->is($round))->toBeTrue()
            ->and(flatNamedOn($round))->toBe([flatNameOf($m)]);
    });

    it('records the replacement in the fake', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        $m = ReviewerTestModel::create();
        Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        app(ApprovalsManager::class)->for($release)->replaceFlatApprovers([$m]);

        $recorded = $fake->recorded(ApprovalOperation::Replace);

        expect($recorded)->toHaveCount(1)
            ->and($recorded[0]->context['stage'])->toBeNull()
            ->and($recorded[0]->context['approvers'])->toBe([$m]);

        Approvals::assertApproversReplaced($release);

        expect(fn () => Approvals::assertApproversReplaced($release, 'engineering'))->toThrow(AssertionFailedError::class);
    });
});

describe('stage replacements', function (): void {
    it('announce who joined and who left as well', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($release)->stages([new StageDefinition([$a, $b], name: 'engineering')])->open();

        Event::fake([ApprovalApproversReplaced::class, ApprovalStageOpened::class]);

        Approvals::for($release)->replaceApprovers('engineering', [$m]);

        Event::assertDispatched(ApprovalApproversReplaced::class, fn (ApprovalApproversReplaced $event): bool => $event->request->is($round)
            && $event->stage?->name === 'engineering'
            && count($event->added) === 1 && $event->added[0]->matches($m)
            && count($event->removed) === 2);
        Event::assertDispatchedTimes(ApprovalStageOpened::class, 1);
    });

    it('refuse an excluded approver', function (): void {
        $release = ReleaseTestModel::create();
        $requester = ReviewerTestModel::create();

        Approvals::request($release)->excluding([$requester])->stages([new StageDefinition([ReviewerTestModel::create()], name: 'engineering')])->open();

        expect(fn () => Approvals::for($release)->replaceApprovers('engineering', [$requester]))
            ->toThrow(InvalidApprovalRequestException::class, 'is excluded from deciding');
    });
});
