<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ReplacesApprovers;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
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
 * cancelled for each), ApprovalApproversReplaced names who joined and who left, then
 * ApprovalStageOpened announces the stage to its new approvers. The round never
 * resolves here: ApprovalRequestResolved does not fire. An actor excluded from deciding
 * the round cannot be named.
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
    use ReplacesApprovers;
    use ResolvesApproval;

    /**
     * Replace the pending approvers of the subject's open stage `$stage` (by name) —
     * on `$request` when given, which must belong to the subject, else on the subject's
     * latest open round.
     *
     * @param  list<Model>  $approvers
     *
     * @throws InvalidApprovalRequestException when no approver is given, the round is flat or not the subject's, the stage is unknown or not open, an approver already decided it or is excluded from deciding, or the stage could not resolve (or would settle at once) with them
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

        /** @var list<NamedApprover> $before */
        $before = [];

        $replaced = $round->getConnection()->transaction(function () use ($round, $subject, $stage, $approvers, &$retired, &$before): ApprovalRequestStage {
            $retired = [];

            $locked = $this->lockOpenRound($round, $subject);
            $target = $this->openStageNamed($locked, $stage);

            $kept = $this->decidedApprovers($target->decisions()->getQuery(), $target->namedApprovers());

            foreach ($approvers as $approver) {
                if (NamedApprover::listIncludes($kept, $approver)) {
                    throw InvalidApprovalRequestException::alreadyDecided($approver, $locked, $target);
                }
            }

            $before = $target->namedApprovers();
            $added = ApproverList::name($approvers, $subject);

            $this->ensureNoneExcluded($added, $subject, $locked);

            $next = [...$kept, ...$added];

            // "Every named approver" stays every named approver; an explicit count stays.
            $current = $target->required_approvers ?? 0;
            $required = ApproverList::required($next, $current === count($before) ? null : $current);

            ApproverList::ensureReachable($target->rule, $target->quorum, $required, $next);

            $target->approvers = ApproverList::payload($next);
            $target->required_approvers = $required;
            $target->save();

            if ($locked->stageOutcome($target) !== null) {
                throw InvalidApprovalRequestException::replacementSettlesStage($locked, $target);
            }

            $locked->required_approvers = max(0, ($locked->required_approvers ?? 0) - $current + $required);
            $locked->save();

            $retired = $this->retireAsksOutside($target->decisions()->getQuery(), $next);

            return $target;
        });

        $round->refresh();

        $this->announceReplacement($retired, $round, $replaced, $before, $replaced->namedApprovers());

        ApprovalStageOpened::dispatch($replaced);

        return $round;
    }

    /**
     * Lock the round's row and read it back: it must still be pending, within its expiry
     * and staged.
     */
    private function lockOpenRound(ApprovalRequest $round, Model $subject): ApprovalRequest
    {
        $locked = $this->lockPendingRound($round, $subject);

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
}
