<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

final class ApprovalStageCleared
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ApprovalRequestStage $stage,
    ) {}
}
