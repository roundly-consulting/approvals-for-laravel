<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * The optional decision gate (`approvals.authorization`), shared by the decision actions
 * and the threshold re-check.
 *
 * @internal
 */
final class DecisionGate
{
    /**
     * The flag is env-backed, so it arrives as a string ('1', 'true', 'yes', 'on');
     * comparing it `=== true` failed open for every one of them.
     */
    public static function enabled(): bool
    {
        return Config::boolean('approvals.authorization.enabled');
    }

    /**
     * Not set (absent, null or blank) means the documented ability; anything else must be
     * a string — a malformed value never silently checks a different gate than the host
     * configured.
     */
    public static function ability(): string
    {
        $configured = config('approvals.authorization.ability');

        return $configured === null || (is_string($configured) && trim($configured) === '')
            ? 'decide-approval'
            : Config::requireString('approvals.authorization.ability');
    }

    /**
     * Whether the actor may decide on the approvable: always, while the gate is off.
     */
    public static function allows(Model $actor, Model $approvable): bool
    {
        if (! self::enabled()) {
            return true;
        }

        return Gate::forUser($actor)->allows(self::ability(), [$approvable]);
    }
}
