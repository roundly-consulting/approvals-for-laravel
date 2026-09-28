<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

/**
 * Names the slot a decision occupies: a standalone decision on the approvable, one
 * request, or one stage of a staged request. An actor holds at most one live decision
 * per slot, which the `approvals_live_decision_unique` index enforces.
 *
 * @internal
 */
final class DecisionScope
{
    public const string STANDALONE = '';

    public static function key(mixed $requestId, mixed $stageId = null): string
    {
        if (! is_int($requestId) && ! is_string($requestId)) {
            return self::STANDALONE;
        }

        $key = 'r'.$requestId;

        if (is_int($stageId) || is_string($stageId)) {
            $key .= ':s'.$stageId;
        }

        return $key;
    }
}
