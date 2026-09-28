<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

final class ToggleApprovalAction
{
    use AuthorizesDecisions;

    /**
     * Toggle the actor's approval of the approvable: a created row is approved, toggling again
     * soft-deletes it.
     *
     * @return bool true when the approval was created, false when it was removed
     */
    public function execute(Model $actor, Model $approvable): bool
    {
        $this->authorizeDecision($actor, $approvable);

        $model = ApprovalModelResolver::class();

        $approval = $model::query()
            ->whereMorphedTo('actor', $actor)
            ->whereMorphedTo('approvable', $approvable)
            ->firstOrCreate([
                'actor_id' => $actor->getKey(),
                'actor_type' => $actor->getMorphClass(),
                'approvable_id' => $approvable->getKey(),
                'approvable_type' => $approvable->getMorphClass(),
            ], [
                'status' => ApprovalStatus::Approved,
            ]);

        if (! $approval->wasRecentlyCreated) {
            $approval->delete();
        }

        ApprovalToggled::dispatch($actor, $approvable, $approval->wasRecentlyCreated);

        return $approval->wasRecentlyCreated;
    }
}
