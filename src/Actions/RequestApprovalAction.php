<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Events\ApprovalRequested;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\LiveDecisions;

final class RequestApprovalAction
{
    use AuthorizesDecisions;
    use ResolvesApproval;

    /**
     * Ask the actor for a decision: record a pending decision in its slot (the given
     * request, the approvable's latest open request, or standalone). When the actor
     * already holds a live decision there, that decision is returned unchanged.
     */
    public function execute(
        Model $actor,
        Model $approvable,
        ?DecisionData $data = null,
        ?ApprovalRequest $request = null,
    ): Approval {
        // The asked actor must be one who may decide on the approvable.
        $this->authorizeDecision($actor, $approvable);

        $data ??= DecisionData::pending();

        $target = $this->decisionTarget($actor, $approvable, $request, actsForOthers: false);

        $this->lapseOverdueDecision($target);

        $asked = null;

        $approval = $this->writeInSlot(function () use ($target, $data, &$asked): Approval {
            $asked = null;

            $live = app(LiveDecisions::class)->in($target);

            if ($live instanceof Approval) {
                return $live;
            }

            $approval = $this->newApprovalFor($target);

            if ($data->reason !== null) {
                $approval->reason = $data->reason;
            }

            if ($data->expiresAt !== null) {
                $approval->expires_at = CarbonImmutable::instance($data->expiresAt->toDateTimeImmutable());
            }

            $approval->save();

            return $asked = $approval;
        });

        if ($asked instanceof Approval) {
            ApprovalRequested::dispatch($asked);
        }

        return $approval;
    }
}
