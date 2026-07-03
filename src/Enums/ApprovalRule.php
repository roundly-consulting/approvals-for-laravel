<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * The resolution strategy for an approval request.
 *
 * Ships the shared {@see Helpers} trait from enums-for-laravel, adding
 * value/label/option helpers (`values()`, `labels()`, `options()`,
 * `toOptions()`, `validationRule()`, `readable()`, case lookups) on top of the
 * domain-specific `isWeighted()` method below.
 */
enum ApprovalRule: string
{
    use Helpers;

    /** Every approver must approve. */
    case Unanimous = 'unanimous';

    /** A configurable number of approvals is required. */
    case Quorum = 'quorum';

    /** A single approval resolves the request. */
    case Any = 'any';

    /** Approvals carry a weight; the request resolves once the summed weight reaches the threshold. */
    case Weighted = 'weighted';

    /**
     * Whether this rule resolves on the summed weight of approvals rather than a raw headcount.
     */
    public function isWeighted(): bool
    {
        return $this === self::Weighted || $this === self::Quorum;
    }
}
