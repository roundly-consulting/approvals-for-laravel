<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

final class ApproveAction
{
    use AuthorizesDecisions;
    use ResolvesApproval;

    public function execute(
        Model $actor,
        Model $approvable,
        ?DecisionData $data = null,
        ?ApprovalRequest $request = null,
    ): Approval {
        // Authorize against the acting model before delegation is unwound.
        $this->authorizeDecision($actor, $approvable);

        $data ??= DecisionData::approved();

        $decidedBy = null;
        $effectiveActor = $this->effectiveActor($actor, $decidedBy);

        $request = $this->requestFor($approvable, $request);

        $approval = $this->activeApprovalFor($effectiveActor, $approvable);

        // Already approved and active: idempotent no-op.
        if ($approval->exists && $approval->status === ApprovalStatus::Approved) {
            return $approval;
        }

        $from = $approval->status;

        if ($request instanceof ApprovalRequest) {
            $approval->approval_request_id = $request->getKey();
            $approval->approval_request_type = $request->getMorphClass();
        }

        $this->applyDecisionContext($approval, $effectiveActor, $decidedBy, $approvable, $data->weight);
        $this->attachStage($approval, $request);

        $approval->approve($data->reason, $data->expiresAt);

        ApprovalApproved::dispatch($approval);
        ApprovalStatusChanged::dispatch($approval, $from, ApprovalStatus::Approved, $effectiveActor);

        $request?->resolve();

        return $approval;
    }
}
