<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Approvals\DataTransferObjects\ThresholdVerdict;

/**
 * A threshold re-check found that a round can no longer reach its threshold and closed
 * it — voided (cancelled) or failed (rejected), per `$verdict->closedAs`. Fires after
 * ApprovalRequestResolved and ApprovalStatusChanged.
 */
final class ApprovalThresholdUnreachable
{
    use Dispatchable;

    public function __construct(
        public ThresholdVerdict $verdict,
    ) {}
}
