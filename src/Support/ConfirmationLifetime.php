<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Reads `approvals.confirmations.expiry`: how long, in seconds, a confirmation asked for
 * without an explicit expiry stays open. Not set (null or blank) means such a
 * confirmation never expires.
 *
 * @internal
 */
final class ConfirmationLifetime
{
    public static function seconds(): ?int
    {
        $configured = config('approvals.confirmations.expiry');

        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return null;
        }

        // Env values arrive as strings: an integer string is accepted, anything else fails loudly.
        return Config::integer('approvals.confirmations.expiry', 1, min: 1);
    }
}
