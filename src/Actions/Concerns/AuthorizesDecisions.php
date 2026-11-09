<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Support\DecisionGate;

/**
 * Shared building block of the decision actions.
 *
 * @internal
 */
trait AuthorizesDecisions
{
    /**
     * When authorization is enabled, ensure the actor may decide on the approvable.
     */
    protected function authorizeDecision(Model $actor, Model $approvable): void
    {
        if (! DecisionGate::enabled()) {
            return;
        }

        try {
            Gate::forUser($actor)->authorize(DecisionGate::ability(), [$approvable]);
        } catch (AuthorizationException) {
            throw UnauthorizedApprovalException::forActor($actor);
        }
    }
}
