<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ReplacesApprovers;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApproverList;

/**
 * Hands a flat (single-stage) approval round to other approvers, in place — the flat
 * counterpart of {@see ReplaceStageApproversAction}.
 *
 * The approvers who already decided the round stay named, and their decisions keep
 * counting; the approvers still to decide are replaced by the ones given. The round
 * keeps its rule, quorum and absolute expiry. A round that needed every named approver
 * keeps needing every one (its required count follows the new list); an explicit
 * required count stays as it was. The replaced approvers' outstanding asks are retired
 * (ApprovalCancelled and ApprovalStatusChanged pending → cancelled for each), then
 * ApprovalApproversReplaced names who joined and who left. The round never resolves
 * here: ApprovalRequestResolved does not fire.
 *
 * The write runs under the round's row lock, the one every decision takes before it
 * writes, so a decision by an approver the replacement removed is refused when it
 * reaches the lock.
 *
 * No actor is involved and no authorization gate runs: check who may reassign the round
 * before calling it.
 */
final class ReplaceRequestApproversAction
{
    use ReplacesApprovers;
    use ResolvesApproval;

    /**
     * Replace the pending approvers of the subject's flat round — `$request` when given,
     * which must belong to the subject, else the subject's latest open round.
     *
     * @param  list<Model>  $approvers
     *
     * @throws InvalidApprovalRequestException when no approver is given, the round is staged or not the subject's, an approver already decided it or is excluded from deciding, or the round could not resolve (or would settle at once) with them
     * @throws ClosedApprovalRequestException when the round is closed or past its expiry
     */
    public function execute(Model $subject, array $approvers, ?ApprovalRequest $request = null): ApprovalRequest
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

        $after = $round->getConnection()->transaction(function () use ($round, $subject, $approvers, &$retired, &$before): array {
            $retired = [];

            $locked = $this->lockPendingRound($round, $subject);

            if ($locked->staged) {
                throw InvalidApprovalRequestException::staged($locked);
            }

            $before = $locked->namedApprovers();
            $kept = $this->decidedApprovers($locked->decisions()->getQuery(), $before);

            foreach ($approvers as $approver) {
                if (NamedApprover::listIncludes($kept, $approver)) {
                    throw InvalidApprovalRequestException::alreadyDecidedRound($approver, $locked);
                }
            }

            $added = ApproverList::name($approvers, $subject);

            $this->ensureNoneExcluded($added, $subject, $locked);

            $next = [...$kept, ...$added];

            // "Every named approver" stays every named approver; an explicit count stays.
            $current = $locked->required_approvers ?? 0;
            $required = ApproverList::required($next, $current === count($before) ? null : $current);

            ApproverList::ensureReachable($locked->rule, $locked->quorum, $required, $next);

            $locked->approvers = ApproverList::payload($next);
            $locked->required_approvers = $required;
            $locked->save();

            if ($locked->flatOutcome() !== null) {
                throw InvalidApprovalRequestException::replacementSettlesRound($locked);
            }

            $retired = $this->retireAsksOutside($locked->decisions()->getQuery(), $next);

            return $next;
        });

        $round->refresh();

        $this->announceReplacement($retired, $round, null, $before, $after);

        return $round;
    }
}
