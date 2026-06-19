<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Models\Approval;

interface GivesApprovalsInterface
{
    /**
     * @return MorphMany<Approval, Model>
     */
    public function approvals(): MorphMany;

    public function hasApproved(Model $model): bool;

    public function toggleApproval(Model $model): bool;
}
