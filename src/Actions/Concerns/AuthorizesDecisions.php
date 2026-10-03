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

        // Not set (absent, null or blank) means the documented ability; anything else must be
        // a string — a malformed value never silently checks a different gate than the host configured.
        $configured = config('approvals.authorization.ability');
        $ability = $configured === null || (is_string($configured) && trim($configured) === '')
            ? 'decide-approval'
            : Config::requireString('approvals.authorization.ability');

        try {
            Gate::forUser($actor)->authorize($ability, [$approvable]);
        } catch (AuthorizationException) {
            throw UnauthorizedApprovalException::forActor($actor);
        }
    }
}
