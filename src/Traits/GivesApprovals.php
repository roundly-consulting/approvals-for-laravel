<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\CancelApprovalAction;
use RoundlyConsulting\Approvals\Actions\RejectAction;
use RoundlyConsulting\Approvals\Actions\RevokeApprovalDelegationAction;
use RoundlyConsulting\Approvals\Actions\ToggleApprovalAction;
use RoundlyConsulting\Approvals\Builders\PendingDelegation;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

/**
 * Grants a model the ability to give approvals to other models (the actor side).
 *
 * @mixin Model
 */
trait GivesApprovals
{
    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(ApprovalModelResolver::class(), 'actor');
    }

    /**
     * Whether this actor currently holds an approved decision for the model.
     */
    public function hasApproved(Model $model): bool
    {
        return $this->approvals()
            ->whereMorphedTo('approvable', $model)
            ->where('status', ApprovalStatus::Approved)
            ->exists();
    }

    /**
     * Whether this actor currently holds a rejection for the model.
     */
    public function hasRejected(Model $model): bool
    {
        return $this->approvals()
            ->whereMorphedTo('approvable', $model)
            ->where('status', ApprovalStatus::Rejected)
            ->exists();
    }

    public function approvalFor(Model $model): ?Approval
    {
        return $this->approvals()
            ->whereMorphedTo('approvable', $model)
            ->latest('id')
            ->first();
    }

    public function approve(Model $model, ?string $reason = null): Approval
    {
        return app(ApproveAction::class)->execute($this, $model, DecisionData::approved($reason));
    }

    public function reject(Model $model, ?string $reason = null): Approval
    {
        return app(RejectAction::class)->execute($this, $model, DecisionData::rejected($reason));
    }

    public function cancelApproval(Model $model, ?string $reason = null): ?Approval
    {
        return app(CancelApprovalAction::class)->execute($this, $model, $reason);
    }

    /**
     * Toggle this actor's approval of the given model.
     *
     * @return bool true when the approval was created, false when it was removed
     */
    public function toggleApproval(Model $model): bool
    {
        return app(ToggleApprovalAction::class)->execute($this, $model);
    }

    /**
     * Delegations this approver has handed out (as the delegator).
     *
     * @return MorphMany<ApprovalDelegation, $this>
     */
    public function approvalDelegations(): MorphMany
    {
        return $this->morphMany(ApprovalDelegationModelResolver::class(), 'delegator');
    }

    /**
     * Hand this approver's authority to another model. Returns a fluent builder so a
     * time window can be attached: delegateApprovalsTo($x)->until($when).
     */
    public function delegateApprovalsTo(Model $delegate): PendingDelegation
    {
        return new PendingDelegation($this, $delegate);
    }

    /**
     * Revoke this approver's active delegations, optionally limited to one delegate.
     *
     * @return int the number of delegations revoked
     */
    public function revokeApprovalDelegation(?Model $delegate = null): int
    {
        return app(RevokeApprovalDelegationAction::class)->execute($this, $delegate);
    }
}
