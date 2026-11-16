<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * The lifecycle of a single-use confirmation: it is asked for (pending), then the named
 * person confirms or declines it once, or it lapses unanswered.
 */
enum ConfirmationStatus: string
{
    use Helpers;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Declined = 'declined';
    case Expired = 'expired';

    /**
     * A terminal state: the confirmation was used (confirmed or declined) or lapsed.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
