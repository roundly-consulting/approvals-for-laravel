<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApproverList;

/**
 * Hands the open stage of a staged approval round to other approvers, in place — for a
 * host that reassigns the stage ("hand it to my manager") without starting the round
 * over.
 *
 * The approvers who already decided the stage stay named, and their decisions keep
 * counting; the approvers still to decide are replaced by the ones given. The round
 * keeps its absolute expiry and the stage its rule, quorum and opening time. A stage
 * that needed every named approver keeps needing every one (its required count follows
 * the new list); an explicit required count stays as it was. The replaced approvers'
 * outstanding asks are retired (ApprovalCancelled and ApprovalStatusChanged pending →
 * cancelled for each), then ApprovalStageOpened announces the stage to its new
 * approvers. The round never resolves here: ApprovalRequestResolved does not fire.
 *
 * The write runs under the round's row lock, the one every decision takes before it
 * writes, so a decision and a replacement never interleave: a decision by an approver
 * the replacement removed is refused when it reaches the lock.
 *
 * No actor is involved and no authorization gate runs: check who may reassign the
 * stage before calling it.
 */
final class ReplaceStageApproversAction
{
    use ResolvesApproval;

    /**
     * Replace the pending approvers of the subject's open stage `$stage` (by name) —
     * on `$request` when given, which must belong to the subject, else on the subject's
     * latest open round.
     *
     * @param  list<Model>  $approvers
     *
     * @throws InvalidApprovalRequestException when no approver is given, the round is flat or not the subject's, the stage is unknown or not open, an approver already decided it, or the stage could not resolve (or would settle at once) with them
     * @throws ClosedApprovalRequestException when the round is closed or past its expiry
     */
    public function execute(Model $subject, string $stage, array $approvers, ?ApprovalRequest $request = null): ApprovalRequest
    {
        if ($approvers === []) {
            throw InvalidApprovalRequestException::noReplacementApprovers();
        }

        $round = $request ?? $this->openRoundOf($subject);

        $this->ensureRequestBelongsTo($round, $subject);

        /** @var list<Approval> $retired */
        $retired = [];

        $replaced = $round->getConnection()->transaction(function () use ($round, $subject, $stage, $approvers, &$retired): ApprovalRequestStage {
            $retired = [];

            $locked = $this->lockOpenRound($round, $subject);
            $target = $this->openStageNamed($locked, $stage);

            $kept = $this->decidedApprovers($target);

            foreach ($approvers as $approver) {
                if (NamedApprover::listIncludes($kept, $approver)) {
                    throw InvalidApprovalRequestException::alreadyDecided($approver, $locked, $target);
                }
            }

            $next = [...$kept, ...ApproverList::name($approvers, $subject)];

            // "Every named approver" stays every named approver; an explicit count stays.
            $current = $target->required_approvers ?? 0;
            $required = ApproverList::required($next, $current === count($target->namedApprovers()) ? null : $current);

            ApproverList::ensureReachable($target->rule, $target->quorum, $required, $next);

            $target->approvers = ApproverList::payload($next);
            $target->required_approvers = $required;
            $target->save();

            if ($locked->stageOutcome($target) !== null) {
                throw InvalidApprovalRequestException::replacementSettlesStage($locked, $target);
            }

            $locked->required_approvers = max(0, ($locked->required_approvers ?? 0) - $current + $required);
            $locked->save();

            $retired = $this->retireAsksOutside($target, $next);

            return $target;
        });

        foreach ($retired as $ask) {
            ApprovalCancelled::dispatch($ask);
            ApprovalStatusChanged::dispatch($ask, ApprovalStatus::Pending, ApprovalStatus::Cancelled);
        }

        ApprovalStageOpened::dispatch($replaced);

        return $round->refresh();
    }

    /**
     * The subject's latest pending round. A subject whose rounds are all closed is
     * refused as closed, one that never had a round as having none.
     */
    private function openRoundOf(Model $subject): ApprovalRequest
    {
        $model = ApprovalRequestModelResolver::class();

        $round = $model::query()
            ->whereMorphedTo('subject', $subject)
            ->where('status', ApprovalStatus::Pending)
            ->latest('id')
            ->first();

        if ($round instanceof ApprovalRequest) {
            return $round;
        }

        $latest = ApprovalChecker::latestRequest($subject);

        throw $latest instanceof ApprovalRequest
            ? ClosedApprovalRequestException::for($latest)
            : InvalidApprovalRequestException::noRound($subject);
    }

    /**
     * Lock the round's row and read it back: it must still be pending, within its expiry
     * and staged. An overdue round is refused without lapsing it — lapsing would resolve
     * it, which a replacement never does.
     */
    private function lockOpenRound(ApprovalRequest $round, Model $subject): ApprovalRequest
    {
        $locked = $round->newQuery()->whereKey($round->getKey())->lockForUpdate()->first();

        if (! $locked instanceof ApprovalRequest) {
            throw InvalidApprovalRequestException::noRound($subject);
        }

        if ($locked->status !== ApprovalStatus::Pending) {
            throw ClosedApprovalRequestException::for($locked);
        }

        if ($locked->isOverdue()) {
            throw ClosedApprovalRequestException::overdue($locked);
        }

        if (! $locked->staged) {
            throw InvalidApprovalRequestException::notStaged($locked);
        }

        return $locked;
    }

    /**
     * The round's open stage, when it carries the name. Another stage with the name is
     * refused as not open (settled, or not reached yet).
     */
    private function openStageNamed(ApprovalRequest $round, string $name): ApprovalRequestStage
    {
        $open = $round->currentStage();

        if ($open instanceof ApprovalRequestStage && $open->name === $name) {
            return $open;
        }

        $named = $round->stages()->where('name', $name)->first();

        throw $named instanceof ApprovalRequestStage
            ? InvalidApprovalRequestException::stageNotOpen($round, $named)
            : InvalidApprovalRequestException::unknownStage($round, $name);
    }

    /**
     * The approvers holding a decision in force on the stage (approved or rejected), in
     * the stage's order, with the weight each was named with. On a stage opened without
     * names they are the actors who decided it, with the weight their decision carries.
     *
     * @return list<NamedApprover>
     */
    private function decidedApprovers(ApprovalRequestStage $stage): array
    {
        $decisions = $stage->decisions()
            ->live()
            ->inForce()
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])
            ->orderBy('id')
            ->get();

        $isDecided = static fn (NamedApprover $approver): bool => $decisions->contains(
            static fn (Approval $decision): bool => $approver->isActorOf($decision),
        );

        $kept = array_values(array_filter($stage->namedApprovers(), $isDecided));

        foreach ($decisions as $decision) {
            $listed = array_filter($kept, static fn (NamedApprover $approver): bool => $approver->isActorOf($decision));

            if ($listed === []) {
                $kept[] = new NamedApprover($decision->actor_type, $decision->actor_id, $decision->weight);
            }
        }

        return $kept;
    }

    /**
     * Withdraw the outstanding asks on the stage addressed to approvers it no longer
     * names: left live they would keep showing as pending, and the asked approver could
     * no longer answer them.
     *
     * @param  list<NamedApprover>  $named
     * @return list<Approval>
     */
    private function retireAsksOutside(ApprovalRequestStage $stage, array $named): array
    {
        $retired = [];

        $asks = $stage->decisions()
            ->live()
            ->where('status', ApprovalStatus::Pending)
            ->orderBy('id')
            ->get();

        foreach ($asks as $ask) {
            $stillNamed = array_filter($named, static fn (NamedApprover $approver): bool => $approver->isActorOf($ask));

            if ($stillNamed === []) {
                $retired[] = $ask->cancel();
            }
        }

        return $retired;
    }
}
