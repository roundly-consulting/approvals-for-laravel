<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\LiveDecisions;

final class CancelApprovalAction
{
    use AuthorizesDecisions;
    use ResolvesApproval;

    /**
     * Withdraw the actor's live decision (pending, approved or rejected) on the
     * approvable, if it holds one — or, failing that, the latest live decision it made
     * on a delegator's behalf.
     *
     * A withdrawal reaches the same round a decision would: the pinned request, else the
     * approvable's open request, else (a model that never had a request) its standalone
     * decisions. Once that round is closed nothing can be withdrawn from it: the
     * withdrawal is refused with a ClosedApprovalRequestException and the decision keeps
     * counting.
     */
    public function execute(
        Model $actor,
        Model $approvable,
        ?string $reason = null,
        ?ApprovalRequest $request = null,
    ): ?Approval {
        // Withdrawing a decision changes the outcome as much as making one.
        $this->authorizeDecision($actor, $approvable);

        $request = $this->requestFor($approvable, $request);

        $from = null;

        $approval = $this->writeInSlot(function () use ($actor, $approvable, $reason, $request, &$from): ?Approval {
            $this->ensureRoundStillOpen($request);

            $approval = app(LiveDecisions::class)->withdrawableBy($actor, $approvable, $request);

            if (! $approval instanceof Approval) {
                return null;
            }

            $from = $approval->status;

            return $approval->cancel($reason);
        });

        if (! $approval instanceof Approval || ! $from instanceof ApprovalStatus) {
            return null;
        }

        ApprovalCancelled::dispatch($approval);
        ApprovalStatusChanged::dispatch($approval, $from, ApprovalStatus::Cancelled, $actor);

        return $approval;
    }
}
