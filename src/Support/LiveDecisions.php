<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Reads the live decisions the decision actions build on. Resolved from the container
 * so a test can replay the read-then-write race deterministically.
 *
 * @internal
 */
final class LiveDecisions
{
    /**
     * The actor's live decision in the target's slot, if it holds one.
     */
    public function in(DecisionTarget $target): ?Approval
    {
        $model = ApprovalModelResolver::class();

        $approval = $model::query()
            ->whereMorphedTo('actor', $target->actor)
            ->whereMorphedTo('approvable', $target->approvable)
            ->where('decision_scope', $target->scope())
            ->live()
            ->latest('id')
            ->first();

        return $approval instanceof Approval ? $approval : null;
    }

    /**
     * The latest live, in-force decision `$model` can withdraw on the approvable: one it
     * holds as the actor, else one it made on a delegator's behalf. Limited to one
     * request when given.
     */
    public function withdrawableBy(Model $model, Model $approvable, ?ApprovalRequest $request = null): ?Approval
    {
        return $this->withdrawable('actor', $model, $approvable, $request)
            ?? $this->withdrawable('decidedBy', $model, $approvable, $request);
    }

    private function withdrawable(string $relation, Model $model, Model $approvable, ?ApprovalRequest $request): ?Approval
    {
        $class = ApprovalModelResolver::class();

        $query = $class::query()
            ->whereMorphedTo($relation, $model)
            ->whereMorphedTo('approvable', $approvable)
            ->live()
            ->inForce();

        if ($request instanceof ApprovalRequest) {
            $query->where('approval_request_id', $request->getKey());
        }

        $approval = $query->latest('id')->first();

        return $approval instanceof Approval ? $approval : null;
    }
}
