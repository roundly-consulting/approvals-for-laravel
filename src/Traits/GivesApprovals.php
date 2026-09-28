<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

/**
 * Grants a model the ability to give approvals to other models (the actor side).
 * Every state change goes through {@see ApprovalsManager}, so `Approvals::fake()`
 * records it.
 *
 * @mixin Model
 */
trait GivesApprovals
{
    /**
     * The decisions this model has recorded as an actor.
     *
     * Not named `approvals()`: that is the approvable side's relation
     * ({@see HasApprovals}), and a model that both decides and is decided on (a team)
     * uses both traits — two `approvals()` methods would be a fatal trait collision.
     *
     * @return MorphMany<Approval, $this>
     */
    public function givenApprovals(): MorphMany
    {
        return $this->morphMany(ApprovalModelResolver::class(), 'actor');
    }

    /**
     * Whether this actor currently holds an approved decision for the model.
     */
    public function hasApproved(Model $model): bool
    {
        return $this->givenApprovals()
            ->whereMorphedTo('approvable', $model)
            ->where('status', ApprovalStatus::Approved)
            ->exists();
    }

    /**
     * Whether this actor currently holds a rejection for the model.
     */
    public function hasRejected(Model $model): bool
    {
        return $this->givenApprovals()
            ->whereMorphedTo('approvable', $model)
            ->where('status', ApprovalStatus::Rejected)
            ->exists();
    }

    public function approvalFor(Model $model): ?Approval
    {
        return $this->givenApprovals()
            ->whereMorphedTo('approvable', $model)
            ->latest('id')
            ->first();
    }

    public function approve(Model $model, ?string $reason = null): Approval
    {
        return app(ApprovalsManager::class)->for($model)->as($this)->because($reason)->approve();
    }

    public function reject(Model $model, ?string $reason = null): Approval
    {
        return app(ApprovalsManager::class)->for($model)->as($this)->because($reason)->reject();
    }

    public function cancelApproval(Model $model, ?string $reason = null): ?Approval
    {
        return app(ApprovalsManager::class)->for($model)->as($this)->because($reason)->cancel();
    }

    /**
     * Toggle this actor's approval of the given model.
     *
     * @return bool true when the approval was created, false when it was removed
     */
    public function toggleApproval(Model $model): bool
    {
        return app(ApprovalsManager::class)->for($model)->as($this)->toggle();
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
     * Hand this approver's authority to another model, optionally within a window.
     * For the fluent form use `Approvals::delegations($this)->to($delegate)->…->grant()`.
     */
    public function delegateApprovalsTo(
        Model $delegate,
        ?CarbonInterface $from = null,
        ?CarbonInterface $until = null,
    ): ApprovalDelegation {
        $pending = app(ApprovalsManager::class)->delegations($this)->to($delegate);

        if ($from !== null) {
            $pending->from($from);
        }

        if ($until !== null) {
            $pending->until($until);
        }

        return $pending->grant();
    }

    /**
     * Revoke this approver's delegations that are in force or scheduled (not yet ended),
     * optionally limited to one delegate.
     *
     * @return int the number of delegations revoked
     */
    public function revokeApprovalDelegation(?Model $delegate = null): int
    {
        return app(ApprovalsManager::class)->delegations($this)->revoke($delegate);
    }
}
