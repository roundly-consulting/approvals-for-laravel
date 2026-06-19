<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;

final class ApprovalDelegationRevoked
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ApprovalDelegation $delegation,
    ) {}
}
