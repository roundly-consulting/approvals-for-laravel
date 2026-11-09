<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;

/**
 * Whether a named approver can still decide: its model still exists (a soft-deleted one
 * does not), it is not excluded, and — while the decision gate is on — it passes the
 * gate, or holds a delegation in force to a delegate who can decide for it.
 *
 * @internal
 */
final readonly class ApproverAvailability
{
    public function __construct(
        private DelegationResolver $delegations,
    ) {}

    /**
     * @param  list<NamedApprover>  $excluded
     */
    public function canDecide(NamedApprover $approver, Model $subject, array $excluded): bool
    {
        $model = $this->load($approver);

        if (! $model instanceof Model || $this->isExcluded($model, $excluded)) {
            return false;
        }

        if (DecisionGate::allows($model, $subject)) {
            return true;
        }

        foreach ($this->delegations->activeDelegationsFrom($model) as $delegation) {
            $delegate = $delegation->delegate;

            if ($delegate instanceof Model && ! $this->isExcluded($delegate, $excluded) && DecisionGate::allows($delegate, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The approver's model as stored now, through its own query (so a soft-deleted one,
     * or one whose class no longer exists, is gone).
     */
    private function load(NamedApprover $approver): ?Model
    {
        $class = Relation::getMorphedModel($approver->type) ?? $approver->type;

        if (! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->whereKey($approver->id)->first();
    }

    /**
     * @param  list<NamedApprover>  $excluded
     */
    private function isExcluded(Model $model, array $excluded): bool
    {
        return NamedApprover::listIncludes($excluded, $model);
    }
}
