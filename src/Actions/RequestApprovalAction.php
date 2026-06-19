<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Events\ApprovalRequested;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

final class RequestApprovalAction
{
    use ResolvesApproval;

    public function execute(
        Model $actor,
        Model $approvable,
        ?DecisionData $data = null,
        ?ApprovalRequest $request = null,
    ): Approval {
        $data ??= DecisionData::pending();

        $approval = $this->newApprovalFor($actor, $approvable, $request);

        if ($data->reason !== null) {
            $approval->reason = $data->reason;
        }

        if ($data->expiresAt !== null) {
            $approval->expires_at = CarbonImmutable::instance($data->expiresAt->toDateTimeImmutable());
        }

        $approval->save();

        ApprovalRequested::dispatch($approval);

        return $approval;
    }
}
