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
     * on a delegator's behalf. Limited to one request when given.
     */
    public function execute(
        Model $actor,
        Model $approvable,
        ?string $reason = null,
        ?ApprovalRequest $request = null,
    ): ?Approval {
        // Withdrawing a decision changes the outcome as much as making one.
        $this->authorizeDecision($actor, $approvable);

        if ($request instanceof ApprovalRequest) {
            $this->ensureRequestBelongsTo($request, $approvable);
        }

        $approval = app(LiveDecisions::class)->withdrawableBy($actor, $approvable, $request);

        if (! $approval instanceof Approval) {
            return null;
        }

        $from = $approval->status;

        $approval->cancel($reason);

        ApprovalCancelled::dispatch($approval);
        ApprovalStatusChanged::dispatch($approval, $from, ApprovalStatus::Cancelled, $actor);

        return $approval;
    }
}
