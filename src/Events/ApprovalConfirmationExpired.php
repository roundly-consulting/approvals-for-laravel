<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

/**
 * A single-use confirmation was lapsed unanswered (by the sweep or the next answer).
 */
final class ApprovalConfirmationExpired
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ApprovalConfirmation $confirmation,
    ) {}
}
