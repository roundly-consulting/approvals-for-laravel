<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

/**
 * Reads the live decisions the decision actions build on. Resolved from the container
 * so a test can replay the read-then-write race deterministically.
 *
 * @internal
 */
final class LiveDecisions
{
    /**
     * The actor's live decision in the target's slot, if it holds one.
     */
    public function in(DecisionTarget $target): ?Approval
    {
        $model = ApprovalModelResolver::class();

        $approval = $model::query()
            ->whereMorphedTo('actor', $target->actor)
            ->whereMorphedTo('approvable', $target->approvable)
            ->where('decision_scope', $target->scope())
            ->live()
            ->latest('id')
            ->first();

        return $approval instanceof Approval ? $approval : null;
    }

    /**
     * The latest live, in-force decision `$model` can withdraw on the approvable: one it
     * holds as the actor, else one it made on behalf of a delegator whose delegation to
     * it is still in force. Limited to one request when given — and, on a staged
     * request, to its open stage: a settled stage's decisions are what settled it.
     */
    public function withdrawableBy(Model $model, Model $approvable, ?ApprovalRequest $request = null): ?Approval
    {
        $stage = $request instanceof ApprovalRequest && $request->staged ? $request->currentStage() : null;

        return $this->withdrawable('actor', $model, $approvable, $request, $stage)
            ?? $this->withdrawableAsDelegate($model, $approvable, $request, $stage);
    }

    /**
     * The latest decision `$delegate` made for a delegator that still delegates to it.
     * Once that delegation is revoked or has ended, the delegate no longer speaks for the
     * delegator — not even to undo what it decided for them.
     */
    private function withdrawableAsDelegate(
        Model $delegate,
        Model $approvable,
        ?ApprovalRequest $request,
        ?ApprovalRequestStage $stage,
    ): ?Approval {
        $delegations = app(DelegationResolver::class)->activeDelegationsTo($delegate);

        if ($delegations->isEmpty()) {
            return null;
        }

        return $this->withdrawable('decidedBy', $delegate, $approvable, $request, $stage, static function (Builder $query) use ($delegations): void {
            $query->where(static function (Builder $actors) use ($delegations): void {
                foreach ($delegations as $delegation) {
                    $actors->orWhere(static function (Builder $actor) use ($delegation): void {
                        $actor->where('actor_type', $delegation->delegator_type)
                            ->where('actor_id', $delegation->delegator_id);
                    });
                }
            });
        });
    }

    /**
     * @param  (Closure(Builder<Approval>): void)|null  $constrain
     */
    private function withdrawable(
        string $relation,
        Model $model,
        Model $approvable,
        ?ApprovalRequest $request,
        ?ApprovalRequestStage $stage,
        ?Closure $constrain = null,
    ): ?Approval {
        $class = ApprovalModelResolver::class();

        $query = $class::query()
            ->whereMorphedTo($relation, $model)
            ->whereMorphedTo('approvable', $approvable)
            ->live()
            ->inForce();

        if ($request instanceof ApprovalRequest) {
            $query->where('approval_request_id', $request->getKey());

            if ($request->staged) {
                // No open stage means nothing in the request can be withdrawn.
                $query->where('approval_request_stage_id', $stage?->getKey());
            }
        }

        if ($constrain instanceof Closure) {
            $constrain($query);
        }

        $approval = $query->latest('id')->first();

        return $approval instanceof Approval ? $approval : null;
    }
}
