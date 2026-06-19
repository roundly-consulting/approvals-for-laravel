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

    public function hasRejected(Model $model): bool;

    public function approvalFor(Model $model): ?Approval;

    public function approve(Model $model, ?string $reason = null): Approval;

    public function reject(Model $model, ?string $reason = null): Approval;

    public function cancelApproval(Model $model, ?string $reason = null): ?Approval;

    public function toggleApproval(Model $model): bool;
}
