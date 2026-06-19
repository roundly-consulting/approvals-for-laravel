<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Interfaces\ProvidesApprovalWeight;

/**
 * Resolves the weight an actor carries towards a weighted/quorum threshold.
 *
 * Resolution order: an explicit per-decision override, then the actor's
 * ProvidesApprovalWeight contract, then the default weight of 1.
 */
final class WeightResolver
{
    public function resolve(Model $actor, ?Model $approvable = null, ?int $override = null): int
    {
        if ($override !== null) {
            return max(0, $override);
        }

        if ($actor instanceof ProvidesApprovalWeight) {
            return max(0, $actor->approvalWeight($approvable));
        }

        return 1;
    }
}
