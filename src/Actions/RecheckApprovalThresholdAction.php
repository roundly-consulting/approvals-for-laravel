<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\DataTransferObjects\ThresholdVerdict;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalThresholdUnreachable;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApproverAvailability;
use RoundlyConsulting\Approvals\Support\ExcludedApprovers;

/**
 * Re-checks whether an open round can still reach its threshold with the approvers who
 * can still decide — for a host whose approvers can leave (deleted or soft-deleted),
 * become excluded, or stop passing the decision gate after the round opened.
 *
 * An outstanding named approver can still decide when its model still exists, it is not
 * excluded, and (while the decision gate is on) it passes the gate or holds a delegation
 * in force to a delegate who does. Decisions already in keep counting. A round, or a
 * stage, that names no approvers lets anyone decide, so it always reads as reachable.
 *
 * A flat round is reachable when its rule can still approve it. A staged round is
 * reachable when every stage still to settle can still clear — or, on a round that
 * continues past a rejected stage, when one stage cleared already or one still can.
 *
 * Given `$closeAs` (cancelled to void it, rejected to fail it), an unreachable round is
 * closed the way the engine closes one — its outstanding asks retired,
 * ApprovalRequestResolved and ApprovalStatusChanged — then ApprovalThresholdUnreachable
 * fires. The check and the status write run under the round's row lock, the one every
 * decision and replacement takes, so neither can change the verdict before it is acted on.
 */
final class RecheckApprovalThresholdAction
{
    use ResolvesApproval;

    public function __construct(
        private readonly ApproverAvailability $availability,
        private readonly ExcludedApprovers $exclusions,
    ) {}

    /**
     * Re-check the subject's latest open round — `$request` when given, which must belong
     * to the subject.
     *
     * @return ThresholdVerdict|null the verdict, or null when there is no open round to check
     *
     * @throws InvalidStatusTransitionException when `$closeAs` is not cancelled or rejected
     * @throws InvalidApprovalRequestException when `$request` belongs to another subject
     */
    public function execute(Model $subject, ?ApprovalRequest $request = null, ?ApprovalStatus $closeAs = null): ?ThresholdVerdict
    {
        if ($closeAs !== null && $closeAs !== ApprovalStatus::Cancelled && $closeAs !== ApprovalStatus::Rejected) {
            throw InvalidStatusTransitionException::notAnUnreachableOutcome($closeAs);
        }

        $round = $this->roundOf($subject, $request);

        if (! $round instanceof ApprovalRequest || $round->lapseIfOverdue()) {
            return null;
        }

        $verdict = $round->getConnection()->transaction(function () use ($round, $subject, $closeAs): ?ThresholdVerdict {
            $locked = $round->newQuery()->whereKey($round->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ApprovalRequest || $locked->status !== ApprovalStatus::Pending) {
                return null;
            }

            $verdict = $this->judge($locked, $subject);

            if (! $verdict->reachable && $closeAs !== null && $locked->claimFinal($closeAs)) {
                return $verdict->withClosedAs($closeAs);
            }

            return $verdict;
        });

        if ($verdict instanceof ThresholdVerdict && $verdict->closedAs !== null) {
            $verdict->request->announceFinal();

            ApprovalThresholdUnreachable::dispatch($verdict);
        }

        $round->refresh();

        return $verdict;
    }

    private function roundOf(Model $subject, ?ApprovalRequest $request): ?ApprovalRequest
    {
        if ($request instanceof ApprovalRequest) {
            $this->ensureRequestBelongsTo($request, $subject);

            $request->refresh();

            return $request->status === ApprovalStatus::Pending ? $request : null;
        }

        $model = ApprovalRequestModelResolver::class();

        $round = $model::query()
            ->whereMorphedTo('subject', $subject)
            ->where('status', ApprovalStatus::Pending)
            ->latest('id')
            ->first();

        return $round instanceof ApprovalRequest ? $round : null;
    }

    private function judge(ApprovalRequest $round, Model $subject): ThresholdVerdict
    {
        $excluded = $this->exclusions->for($subject, $round);

        if (! $round->staged) {
            $unavailable = [];

            $reachable = $this->canStillApprove(
                $round->rule,
                $round->quorum,
                $round->required_approvers ?? 0,
                $round->namedApprovers(),
                $round->decisions()->getQuery(),
                $subject,
                $excluded,
                $unavailable,
            );

            return new ThresholdVerdict($round, $reachable, $unavailable);
        }

        /** @var Collection<int, ApprovalRequestStage> $stages */
        $stages = $round->stages()->get();

        $cleared = $stages->contains(static fn (ApprovalRequestStage $stage): bool => $stage->status === ApprovalStatus::Approved);

        $unavailable = [];
        $firstUnreachable = null;
        $anyReachable = false;

        foreach ($stages as $stage) {
            if ($stage->status->isFinal()) {
                continue;
            }

            $reachable = $this->canStillApprove(
                $stage->rule,
                $stage->quorum,
                $stage->required_approvers ?? 0,
                $stage->namedApprovers(),
                $stage->decisions()->getQuery(),
                $subject,
                $excluded,
                $unavailable,
            );

            $anyReachable = $anyReachable || $reachable;
            $firstUnreachable ??= $reachable ? null : $stage;
        }

        $reachable = $round->reject_on_stage_rejection
            ? ! $firstUnreachable instanceof ApprovalRequestStage
            : $cleared || $anyReachable || ! $firstUnreachable instanceof ApprovalRequestStage;

        return new ThresholdVerdict($round, $reachable, $unavailable, $reachable ? null : $firstUnreachable);
    }

    /**
     * Whether the rule can still approve: the decisions in force, plus what the
     * outstanding named approvers who can still decide could add. The outstanding ones
     * who cannot are appended to `$unavailable`.
     *
     * @param  list<NamedApprover>  $named
     * @param  Builder<Approval>  $decisions
     * @param  list<NamedApprover>  $excluded
     * @param  list<NamedApprover>  $unavailable
     */
    private function canStillApprove(
        ApprovalRule $rule,
        ?int $quorum,
        int $required,
        array $named,
        Builder $decisions,
        Model $subject,
        array $excluded,
        array &$unavailable,
    ): bool {
        // Without names anyone may decide: nothing says the threshold is out of reach.
        if ($named === []) {
            return true;
        }

        $decided = $decisions
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])
            ->get()
            ->filter(static fn (Approval $decision): bool => $decision->isInForce());

        $approved = $decided->where('status', ApprovalStatus::Approved);
        $rejectedCount = $decided->where('status', ApprovalStatus::Rejected)->count();

        $available = [];

        foreach ($named as $approver) {
            if ($decided->contains(static fn (Approval $decision): bool => $approver->isActorOf($decision))) {
                continue;
            }

            if ($this->availability->canDecide($approver, $subject, $excluded)) {
                $available[] = $approver;
            } else {
                $unavailable[] = $approver;
            }
        }

        $availableWeight = array_sum(array_map(static fn (NamedApprover $approver): int => $approver->weight, $available));

        return match ($rule) {
            ApprovalRule::Any => $approved->isNotEmpty() || $available !== [],
            ApprovalRule::Unanimous => $rejectedCount === 0 && $approved->count() + count($available) >= $required,
            ApprovalRule::Quorum, ApprovalRule::Weighted => (int) $approved->sum('weight') + $availableWeight >= ($quorum ?? $required),
        };
    }
}
