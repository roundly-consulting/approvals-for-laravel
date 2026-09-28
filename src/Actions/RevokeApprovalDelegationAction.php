<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Events\ApprovalDelegationRevoked;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;

final class RevokeApprovalDelegationAction
{
    /**
     * Revoke every delegation the delegator has in force or scheduled (not yet ended),
     * optionally limited to a single delegate.
     *
     * @return int the number of delegations revoked
     */
    public function execute(Model $delegator, ?Model $delegate = null): int
    {
        $model = ApprovalDelegationModelResolver::class();

        $query = $model::query()
            ->whereMorphedTo('delegator', $delegator)
            ->inForceOrScheduled();

        if ($delegate instanceof Model) {
            $query->whereMorphedTo('delegate', $delegate);
        }

        $count = 0;

        foreach ($query->get() as $delegation) {
            $delegation->revoke();

            ApprovalDelegationRevoked::dispatch($delegation);

            $count++;
        }

        return $count;
    }
}
