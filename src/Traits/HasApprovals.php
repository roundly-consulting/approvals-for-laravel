<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
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
     * Whether the given actor currently holds an approved decision for this model.
     */
    public function hasBeenApprovedBy(Model $actor): bool
    {
        return $this->approvals()
            ->whereMorphedTo('actor', $actor)
            ->where('status', ApprovalStatus::Approved)
            ->exists();
    }

    /**
     * Whether the given actor currently holds a rejection for this model.
     */
    public function hasBeenRejectedBy(Model $actor): bool
    {
        return $this->approvals()
            ->whereMorphedTo('actor', $actor)
            ->where('status', ApprovalStatus::Rejected)
            ->exists();
    }

    public function isApprovedBy(Model $actor): bool
    {
        return $this->hasBeenApprovedBy($actor);
    }

    /**
     * Number of distinct approved decisions this model currently holds.
     */
    public function approvalCount(): int
    {
        return $this->approvals()
            ->where('status', ApprovalStatus::Approved)
            ->count();
    }

    /**
     * @return Collection<int, Approval>
     */
    public function pendingApprovals(): Collection
    {
        return $this->approvals()
            ->where('status', ApprovalStatus::Pending)
            ->get();
    }
}
