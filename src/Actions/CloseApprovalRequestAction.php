<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * Closes a subject's open approval round from outside, as cancelled or expired — for a
 * host that owns the subject's lifecycle (it cancelled or expired the subject) and must
 * stop a late decision from resolving the round.
 *
 * Each round closes the way the engine closes one it resolves: a conditional status
 * write that only moves it while it is still pending (a decision that resolved it first
 * keeps its outcome), the round's outstanding asks retired (ApprovalCancelled and
 * ApprovalStatusChanged pending → cancelled for each), then ApprovalRequestResolved and
 * ApprovalStatusChanged for the round. A closed round is left alone and fires nothing.
 */
final class CloseApprovalRequestAction
{
    use ResolvesApproval;

    /**
     * Close the subject's open rounds — only `$request` when given, which must belong to
     * the subject. A round past its expiry lapses as expired, whatever the outcome.
     *
     * @return int the number of rounds this call closed (0 when none was open)
     *
     * @throws InvalidStatusTransitionException when the outcome is not cancelled or expired
     * @throws InvalidApprovalRequestException when `$request` belongs to another subject
     */
    public function execute(
        Model $subject,
        ApprovalStatus $outcome = ApprovalStatus::Cancelled,
        ?ApprovalRequest $request = null,
    ): int {
        if ($outcome !== ApprovalStatus::Cancelled && $outcome !== ApprovalStatus::Expired) {
            throw InvalidStatusTransitionException::notAClosingOutcome($outcome);
        }

        if ($request instanceof ApprovalRequest) {
            $this->ensureRequestBelongsTo($request, $subject);

            return $request->close($outcome) ? 1 : 0;
        }

        $model = ApprovalRequestModelResolver::class();

        $rounds = $model::query()
            ->whereMorphedTo('subject', $subject)
            ->where('status', ApprovalStatus::Pending)
            ->orderBy('id')
            ->get();

        $closed = 0;

        foreach ($rounds as $round) {
            if ($round->close($outcome)) {
                $closed++;
            }
        }

        return $closed;
    }
}
