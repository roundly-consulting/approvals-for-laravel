<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

/**
 * A single-use confirmation was declined by its person, with the reason (if any) on the confirmation.
 */
final class ApprovalConfirmationDeclined
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ApprovalConfirmation $confirmation,
        public Model $person,
    ) {}
}
