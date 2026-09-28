<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;

/**
 * Resolves the effective approver behind a decision, honouring active delegations.
 */
final class DelegationResolver
{
    /**
     * Find an active delegation under which the given model is acting as a delegate.
     *
     * The most recently created active delegation wins when several exist.
     */
    public function activeDelegationFor(Model $delegate, ?CarbonInterface $moment = null): ?ApprovalDelegation
    {
        $model = ApprovalDelegationModelResolver::class();

        $delegation = $model::query()
            ->whereMorphedTo('delegate', $delegate)
            ->active($moment)
            ->latest('id')
            ->first();

        return $delegation instanceof ApprovalDelegation ? $delegation : null;
    }

    /**
     * The delegations the given delegator has in force at the moment, newest first.
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function activeDelegationsFrom(Model $delegator, ?CarbonInterface $moment = null): Collection
    {
        $model = ApprovalDelegationModelResolver::class();

        return $model::query()
            ->whereMorphedTo('delegator', $delegator)
            ->active($moment)
            ->latest('id')
            ->get();
    }
}
