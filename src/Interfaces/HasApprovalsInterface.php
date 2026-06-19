<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Models\Approval;

interface HasApprovalsInterface
{
    /**
     * @return MorphMany<Approval, Model>
     */
    public function approvals(): MorphMany;

    public function hasBeenApprovedBy(Model $actor): bool;

    public function hasBeenRejectedBy(Model $actor): bool;

    public function isApprovedBy(Model $actor): bool;

    public function approvalCount(): int;

    /**
     * @return Collection<int, Approval>
     */
    public function pendingApprovals(): Collection;
}
