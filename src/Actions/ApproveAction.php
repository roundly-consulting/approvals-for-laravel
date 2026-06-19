<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
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
        $this->authorizeDecision($actor, $approvable);

        $data ??= DecisionData::approved();

        $request = $this->requestFor($approvable, $request);

        $approval = $this->activeApprovalFor($actor, $approvable);

        // Already approved and active: idempotent no-op.
        if ($approval->exists && $approval->status === ApprovalStatus::Approved) {
            return $approval;
        }

        if ($request instanceof ApprovalRequest) {
            $approval->approval_request_id = $request->getKey();
            $approval->approval_request_type = $request->getMorphClass();
        }

        $approval->approve($data->reason, $data->expiresAt);

        ApprovalApproved::dispatch($approval);

        $request?->resolve();

        return $approval;
    }
}
