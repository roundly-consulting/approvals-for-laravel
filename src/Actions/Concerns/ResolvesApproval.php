<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\DelegationResolver;
use RoundlyConsulting\Approvals\Support\WeightResolver;

trait ResolvesApproval
{
    /**
     * Find the latest active (pending/approved) approval for the pair, or build a fresh one.
     */
    protected function activeApprovalFor(Model $actor, Model $approvable): Approval
    {
        $model = ApprovalModelResolver::class();

        $existing = $model::query()
            ->whereMorphedTo('actor', $actor)
            ->whereMorphedTo('approvable', $approvable)
            ->active()
            ->latest('id')
            ->first();

        if ($existing instanceof Approval) {
            return $existing;
        }

        return $this->newApprovalFor($actor, $approvable);
    }

    /**
     * Build (without saving) a fresh pending approval for the pair.
     */
    protected function newApprovalFor(Model $actor, Model $approvable, ?ApprovalRequest $request = null): Approval
    {
        $model = ApprovalModelResolver::class();

        $approval = new $model;
        $approval->actor_id = $actor->getKey();
        $approval->actor_type = $actor->getMorphClass();
        $approval->approvable_id = $approvable->getKey();
        $approval->approvable_type = $approvable->getMorphClass();
        $approval->status = ApprovalStatus::Pending;

        if ($request instanceof ApprovalRequest) {
            $approval->approval_request_id = $request->getKey();
            $approval->approval_request_type = $request->getMorphClass();
        }

        return $approval;
    }

    /**
     * Resolve the request to attach a decision to: the one passed explicitly, or the
     * latest open request whose subject is the approvable.
     */
    protected function requestFor(Model $approvable, ?ApprovalRequest $request): ?ApprovalRequest
    {
        if ($request instanceof ApprovalRequest) {
            return $request;
        }

        $model = ApprovalRequestModelResolver::class();

        $found = $model::query()
            ->whereMorphedTo('subject', $approvable)
            ->where('status', ApprovalStatus::Pending)
            ->latest('id')
            ->first();

        return $found instanceof ApprovalRequest ? $found : null;
    }

    /**
     * Resolve the effective approver for a decision, honouring active delegations.
     *
     * Returns the model whose authority the decision counts as. When a delegation is
     * in force, the original delegate is returned via $decidedBy so the action can
     * record who physically decided.
     */
    protected function effectiveActor(Model $actor, ?Model &$decidedBy): Model
    {
        $delegation = app(DelegationResolver::class)->activeDelegationFor($actor);

        if ($delegation === null) {
            return $actor;
        }

        $delegator = $delegation->delegator;

        if (! $delegator instanceof Model) {
            return $actor;
        }

        // The acting model decided on behalf of the delegator.
        $decidedBy = $actor;

        return $delegator;
    }

    /**
     * Stamp delegation and weight onto an approval before it is decided.
     *
     * The weight is resolved from the in-memory effective actor (not a reloaded
     * relation) so a ProvidesApprovalWeight value set at runtime is respected.
     */
    protected function applyDecisionContext(
        Approval $approval,
        Model $effectiveActor,
        ?Model $decidedBy,
        Model $approvable,
        ?int $weightOverride = null,
    ): void {
        if ($decidedBy instanceof Model) {
            $approval->decided_by_id = $decidedBy->getKey();
            $approval->decided_by_type = $decidedBy->getMorphClass();
        }

        $approval->weight = app(WeightResolver::class)->resolve($effectiveActor, $approvable, $weightOverride);
    }

    /**
     * Attach the approval to the currently open stage of a staged request, if any.
     */
    protected function attachStage(Approval $approval, ?ApprovalRequest $request): void
    {
        if (! $request instanceof ApprovalRequest || ! $request->staged) {
            return;
        }

        $stage = $request->currentStage();

        if ($stage instanceof ApprovalRequestStage) {
            $approval->approval_request_stage_id = $stage->getKey();
        }
    }
}
