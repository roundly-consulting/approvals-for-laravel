<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Shared building block of the decision actions.
 *
 * @internal
 */
trait AuthorizesDecisions
{
    /**
     * When authorization is enabled, ensure the actor may decide on the approvable.
     *
     * The flag is env-backed, so it arrives as a string ('1', 'true', 'yes', 'on');
     * comparing it `=== true` failed open for every one of them.
     */
    protected function authorizeDecision(Model $actor, Model $approvable): void
    {
        if (! Config::boolean('approvals.authorization.enabled')) {
            return;
        }

        $ability = config('approvals.authorization.ability');
        $ability = is_string($ability) ? $ability : 'decide-approval';

        try {
            Gate::forUser($actor)->authorize($ability, [$approvable]);
        } catch (AuthorizationException) {
            throw UnauthorizedApprovalException::forActor($actor);
        }
    }
}
