<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Models\Approval;
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

    public function hasApproved(Model $model): bool
    {
        return $this->approvals()
            ->whereMorphedTo('approvable', $model)
            ->exists();
    }

    /**
     * Toggle this actor's approval of the given model.
     *
     * @return bool true when the approval was created, false when it was removed
     */
    public function toggleApproval(Model $model): bool
    {
        $approval = $this->approvals()
            ->whereMorphedTo('approvable', $model)
            ->firstOrCreate([
                'approvable_id' => $model->getKey(),
                'approvable_type' => $model->getMorphClass(),
            ]);

        if (! $approval->wasRecentlyCreated) {
            $approval->delete();
        }

        ApprovalToggled::dispatch($this, $model, $approval->wasRecentlyCreated);

        return $approval->wasRecentlyCreated;
    }
}
