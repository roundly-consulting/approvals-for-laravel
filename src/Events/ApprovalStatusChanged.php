<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

/**
 * Umbrella event dispatched for every approval/request status change, alongside the
 * granular per-transition events. Subscribe to this once to observe all transitions.
 */
final class ApprovalStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Model $subject,
        public ApprovalStatus $from,
        public ApprovalStatus $to,
        public ?Model $actor = null,
    ) {}
}
