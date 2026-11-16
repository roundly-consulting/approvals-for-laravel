<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

/**
 * A single-use confirmation was confirmed by its person: the action it is bound to may run.
 */
final class ApprovalConfirmationConfirmed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ApprovalConfirmation $confirmation,
        public Model $person,
    ) {}
}
