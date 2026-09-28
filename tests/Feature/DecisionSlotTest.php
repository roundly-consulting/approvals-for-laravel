<?php

declare(strict_types=1);

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\LiveDecisions;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/**
 * An actor holds one live decision per slot — a standalone decision on the approvable,
 * one request, or one stage — guarded by the `approvals_live_decision_unique` index.
 */
function reviewers(int $count): array
{
    return array_map(fn (): ReviewerTestModel => ReviewerTestModel::create(), range(1, $count));
}

describe('the double-approval race', function (): void {
    it('refuses a second live decision in the same slot at the database', function (): void {
        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        Approval::factory()->forActor($actor)->forApprovable($deployment)->approved()->create();

        expect(fn () => Approval::factory()->forActor($actor)->forApprovable($deployment)->approved()->create())
            ->toThrow(UniqueConstraintViolationException::class);
    });

    it('lets retired decisions pile up beside the live one', function (): void {
        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        Approval::factory()->forActor($actor)->forApprovable($deployment)->cancelled()->count(2)->create();
        Approval::factory()->forActor($actor)->forApprovable($deployment)->expired()->create();
        Approval::factory()->forActor($actor)->forApprovable($deployment)->approved()->create()->delete();
        $live = Approval::factory()->forActor($actor)->forApprovable($deployment)->approved()->create();

        expect(Approval::query()->withTrashed()->count())->toBe(5)
            ->and(Approval::query()->live()->sole()->is($live))->toBeTrue();
    });

    it('counts one actor once when a concurrent approval wins the slot between read and write', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c] = reviewers(3);

        $release->requestApproval([$a, $b, $c], ApprovalRule::Quorum, quorum: 2);

        // The other process: `$a`'s approval has committed…
        $winner = $a->approve($release);

        // …but this process read the slot before it did, so its first read misses it.
        app()->instance(LiveDecisions::class, new class
        {
            private int $reads = 0;

            public function in(DecisionTarget $target): ?Approval
            {
                return $this->reads++ === 0 ? null : (new LiveDecisions)->in($target);
            }
        });

        Event::fake([ApprovalApproved::class]);

        $approval = app(ApproveAction::class)->execute($a, $release);

        expect($approval->is($winner))->toBeTrue()
            ->and(Approval::query()->approved()->count())->toBe(1)
            ->and(Approvals::status($release))->toBe(ApprovalStatus::Pending);

        Event::assertNotDispatched(ApprovalApproved::class);
    });

    it('gives up after three refused writes instead of looping', function (): void {
        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        $actor->approve($deployment);

        // A reader that never sees the committed decision: every write collides.
        app()->instance(LiveDecisions::class, new class
        {
            public function in(DecisionTarget $target): ?Approval
            {
                return null;
            }
        });

        expect(fn () => $actor->reject($deployment))->toThrow(UniqueConstraintViolationException::class)
            ->and(Approval::query()->count())->toBe(1);
    });

    it('keeps tracking the slot under Event::fake()', function (): void {
        Event::fake();

        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        $actor->approve($deployment);
        $actor->approve($deployment);

        expect(Approval::query()->count())->toBe(1)
            ->and($actor->cancelApproval($deployment)?->status)->toBe(ApprovalStatus::Cancelled);
    });
});

describe('the slot a decision belongs to', function (): void {
    it('lets an actor approve a new request after approving an earlier one', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $first = $release->requestApproval([$lead], ApprovalRule::Any);
        $lead->approve($release);

        $second = $release->requestApproval([$lead], ApprovalRule::Any);
        $approval = $lead->approve($release);

        expect($first->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->approval_request_id)->toBe($second->getKey())
            ->and($second->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('lets an approver of two stages clear both', function (): void {
        $release = ReleaseTestModel::create();
        $cto = ReviewerTestModel::create();

        $request = $release->requestStagedApproval([
            new StageDefinition([$cto], ApprovalRule::Any, name: 'engineering'),
            new StageDefinition([$cto], ApprovalRule::Any, name: 'final'),
        ]);

        $cto->approve($release);
        expect(Approvals::currentStage($release)?->name)->toBe('final');

        $cto->approve($release);
        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('lets a toggled standalone approval sit beside a request decision', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        expect($lead->toggleApproval($release))->toBeTrue();

        $request = $release->requestApproval([$lead], ApprovalRule::Any);
        $lead->approve($release);

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and(Approval::query()->approved()->count())->toBe(2);
    });

    it('decides an asked-for decision in place and never duplicates an ask', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = $release->requestApproval([$lead], ApprovalRule::Any);

        $ask = Approvals::for($release)->as($lead)->ask();
        $again = Approvals::for($release)->as($lead)->ask();
        $approval = $lead->approve($release);

        expect($again->is($ask))->toBeTrue()
            ->and($ask->approval_request_id)->toBe($request->getKey())
            ->and($approval->is($ask))->toBeTrue()
            ->and(Approval::query()->count())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });
});

describe('changing your mind', function (): void {
    it('stops counting an approval once its actor rejects', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c] = reviewers(3);

        $release->requestApproval([$a, $b, $c], ApprovalRule::Quorum, quorum: 2);

        $a->approve($release);
        $a->reject($release);
        $b->approve($release);

        expect(Approvals::status($release))->toBe(ApprovalStatus::Pending)
            ->and($a->hasApproved($release))->toBeFalse()
            ->and($a->hasRejected($release))->toBeTrue();
    });

    it('stops counting a rejection once its actor approves', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c, $d] = reviewers(4);

        $release->requestApproval([$a, $b, $c, $d], ApprovalRule::Quorum, quorum: 2);

        $a->reject($release);
        $a->approve($release);
        $b->reject($release);
        $c->reject($release);

        // One approval in, `$d` still to decide: two approvals remain reachable.
        expect(Approvals::status($release))->toBe(ApprovalStatus::Pending)
            ->and($a->hasRejected($release))->toBeFalse()
            ->and($a->hasApproved($release))->toBeTrue();
    });

    it('treats repeating the same decision as a no-op', function (): void {
        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        $first = $actor->reject($deployment, 'no');
        $second = $actor->reject($deployment, 'still no');

        expect($second->is($first))->toBeTrue()
            ->and(Approval::query()->count())->toBe(1);
    });
});

describe('toggle', function (): void {
    it('approves over a held rejection instead of deleting it', function (): void {
        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        $actor->reject($deployment);

        expect($actor->toggleApproval($deployment))->toBeTrue()
            ->and($actor->hasApproved($deployment))->toBeTrue()
            ->and($actor->hasRejected($deployment))->toBeFalse();
    });

    it('counts a toggle towards the open request', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = $release->requestApproval([$lead], ApprovalRule::Any);

        expect($lead->toggleApproval($release))->toBeTrue()
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });
});

describe('withdrawing a delegated decision', function (): void {
    it('lets the delegate withdraw what it decided on the delegator\'s behalf', function (): void {
        $boss = ReviewerTestModel::create();
        $deputy = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        $boss->delegateApprovalsTo($deputy);
        $deputy->approve($deployment);

        $withdrawn = $deputy->cancelApproval($deployment);

        expect($withdrawn?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn?->actor_id)->toBe($boss->getKey())
            ->and($boss->hasApproved($deployment))->toBeFalse();
    });

    it('withdraws only within the pinned request', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $lead->toggleApproval($release);
        $request = $release->requestApproval([$lead, ReviewerTestModel::create()]);

        expect(Approvals::for($release)->as($lead)->within($request)->cancel())->toBeNull();

        $inRequest = $lead->approve($release);

        expect(Approvals::for($release)->as($lead)->within($request)->cancel()?->is($inRequest))->toBeTrue()
            ->and($lead->hasApproved($release))->toBeTrue();
    });

    it('refuses to withdraw within a foreign request', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();
        $foreign = ReleaseTestModel::create()->requestApproval([$lead]);

        expect(fn () => Approvals::for($release)->as($lead)->within($foreign)->cancel())
            ->toThrow(InvalidApprovalRequestException::class);
    });

    it('withdraws a held rejection too', function (): void {
        $actor = ReviewerTestModel::create();
        $deployment = DeploymentTestModel::create();

        $actor->reject($deployment);

        expect($actor->cancelApproval($deployment)?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($actor->hasRejected($deployment))->toBeFalse();
    });
});
