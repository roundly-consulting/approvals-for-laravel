<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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

    public function hasBeenApprovedBy(Model $actor): bool
    {
        return $this->approvals()
            ->whereMorphedTo('actor', $actor)
            ->exists();
    }
}
