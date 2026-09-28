<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalLifetime;

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

        if ($data->expiresAt === null && ($lifetime = ApprovalLifetime::seconds()) !== null) {
            $data = DecisionData::approved($data->reason, CarbonImmutable::now()->addSeconds($lifetime), $data->weight);
        }

        $target = $this->decisionTarget($actor, $approvable, $request);

        $recorded = $this->recordDecision($target, ApprovalStatus::Approved, $data);

        // Approving again in the same slot is an idempotent no-op.
        if (! $recorded->changed) {
            return $recorded->approval;
        }

        $this->announceSuperseded($recorded, $target->actor);

        ApprovalApproved::dispatch($recorded->approval);
        ApprovalStatusChanged::dispatch($recorded->approval, $recorded->from, ApprovalStatus::Approved, $target->actor);

        $target->request?->resolve();

        return $recorded->approval;
    }
}
