<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRejected;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

final class RejectAction
{
    use AuthorizesDecisions;
    use ResolvesApproval;

    public function execute(
        Model $actor,
        Model $approvable,
        ?DecisionData $data = null,
        ?ApprovalRequest $request = null,
    ): Approval {
        $this->authorizeDecision($actor, $approvable);

        $data ??= DecisionData::rejected();

        try {
            $target = $this->decisionTarget($actor, $approvable, $request);

            // Rejecting over an earlier approval withdraws that approval: only the latest
            // decision of an actor counts.
            $recorded = $this->recordDecision($target, ApprovalStatus::Rejected, $data);
        } catch (ClosedApprovalRequestException $closed) {
            return $this->heldInClosedRound($closed, $actor, $approvable, ApprovalStatus::Rejected);
        }

        if (! $recorded->changed) {
            return $recorded->approval;
        }

        $this->announceSuperseded($recorded, $target->actor);

        ApprovalRejected::dispatch($recorded->approval);
        ApprovalStatusChanged::dispatch($recorded->approval, $recorded->from, ApprovalStatus::Rejected, $target->actor);

        $target->request?->resolve();

        return $recorded->approval;
    }
}
