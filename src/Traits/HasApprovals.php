<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

/**
 * Grants a model the ability to receive approvals from other models (the approvable side).
 *
 * @mixin Model
 */
trait HasApprovals
{
    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(ApprovalModelResolver::class(), 'approvable');
    }

    /**
     * Whether the given actor currently holds an approved decision for this model that
     * is still in force (not past its expiry).
     */
    public function hasBeenApprovedBy(Model $actor): bool
    {
        return ApprovalChecker::isApprovedBy($this, $actor);
    }

    /**
     * Whether the given actor currently holds a rejection for this model.
     */
    public function hasBeenRejectedBy(Model $actor): bool
    {
        return ApprovalChecker::isRejectedBy($this, $actor);
    }

    public function isApprovedBy(Model $actor): bool
    {
        return $this->hasBeenApprovedBy($actor);
    }

    /**
     * Number of approved decisions this model currently holds that are still in force.
     */
    public function approvalCount(): int
    {
        return $this->approvals()
            ->where('status', ApprovalStatus::Approved)
            ->inForce()
            ->count();
    }

    /**
     * The pending (asked-for) decisions on this model whose reply-by deadline has not
     * passed.
     *
     * @return Collection<int, Approval>
     */
    public function pendingApprovals(): Collection
    {
        return $this->approvals()
            ->where('status', ApprovalStatus::Pending)
            ->inForce()
            ->get();
    }
}
