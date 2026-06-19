<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

use Illuminate\Database\Eloquent\Model;

/**
 * Implemented by an actor model that carries a weight towards weighted/quorum thresholds.
 */
interface ProvidesApprovalWeight
{
    /**
     * The weight this actor contributes when deciding on the given approvable.
     */
    public function approvalWeight(?Model $approvable = null): int;
}
