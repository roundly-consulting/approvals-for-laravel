<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Reads `approvals.expiry.default`: how long, in seconds, an approval given without an
 * explicit expiry stays valid. Null (the default) means approvals never expire unless
 * one is set per decision.
 *
 * @internal
 */
final class ApprovalLifetime
{
    public static function seconds(): ?int
    {
        $configured = config('approvals.expiry.default');

        if ($configured === null || $configured === '') {
            return null;
        }

        // Env values arrive as strings: an integer string is accepted, anything else fails loudly.
        return Config::integer('approvals.expiry.default', 1, min: 1);
    }
}
