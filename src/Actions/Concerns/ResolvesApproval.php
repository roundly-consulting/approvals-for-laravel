<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

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
}
