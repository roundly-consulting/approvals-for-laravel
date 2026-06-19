<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * Marks a model as a subject that needs sign-off from one or more approvers.
 *
 * @mixin Model
 */
trait RequiresApproval
{
    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequestModelResolver::class(), 'subject');
    }

    /**
     * Open a new approval request requiring sign-off from the given approvers.
     *
     * @param  list<Model>  $approvers
     */
    public function requestApproval(
        array $approvers,
        ApprovalRule $rule = ApprovalRule::Unanimous,
        ?int $quorum = null,
    ): ApprovalRequest {
        $model = ApprovalRequestModelResolver::class();

        $request = new $model;
        $request->subject_id = $this->getKey();
        $request->subject_type = $this->getMorphClass();
        $request->rule = $rule;
        $request->quorum = $quorum;
        $request->required_approvers = count($approvers);
        $request->status = ApprovalStatus::Pending;
        $request->save();

        return $request;
    }

    public function currentApprovalStatus(): ApprovalStatus
    {
        $request = $this->latestApprovalRequest();

        if ($request === null) {
            return ApprovalStatus::Pending;
        }

        return $request->status;
    }

    public function isApproved(): bool
    {
        return $this->currentApprovalStatus() === ApprovalStatus::Approved;
    }

    public function isPendingApproval(): bool
    {
        return $this->currentApprovalStatus() === ApprovalStatus::Pending;
    }

    private function latestApprovalRequest(): ?ApprovalRequest
    {
        return $this->approvalRequests()->latest('id')->first();
    }
}
