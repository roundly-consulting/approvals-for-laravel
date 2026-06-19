<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRejected;
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

        $request = $this->requestFor($approvable, $request);

        $approval = $this->activeApprovalFor($actor, $approvable);

        // An already-approved row is final; record the rejection as a fresh decision.
        if ($approval->exists && $approval->status === ApprovalStatus::Approved) {
            $approval = $this->newApprovalFor($actor, $approvable, $request);
        } elseif ($request instanceof ApprovalRequest) {
            $approval->approval_request_id = $request->getKey();
            $approval->approval_request_type = $request->getMorphClass();
        }

        $approval->reject($data->reason);

        ApprovalRejected::dispatch($approval);

        $request?->resolve();

        return $approval;
    }
}
