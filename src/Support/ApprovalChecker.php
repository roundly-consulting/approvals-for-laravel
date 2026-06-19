<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;

/**
 * Status checks that work against any approvable model without depending on the
 * HasApprovals trait being present. Used by the Blade directives.
 */
final class ApprovalChecker
{
    public static function isApprovedBy(Model $approvable, Model $actor): bool
    {
        return self::query($approvable, $actor)
            ->where('status', ApprovalStatus::Approved)
            ->exists();
    }

    public static function isRejectedBy(Model $approvable, Model $actor): bool
    {
        return self::query($approvable, $actor)
            ->where('status', ApprovalStatus::Rejected)
            ->exists();
    }

    public static function hasPending(Model $approvable): bool
    {
        $model = ApprovalModelResolver::class();

        return $model::query()
            ->whereMorphedTo('approvable', $approvable)
            ->where('status', ApprovalStatus::Pending)
            ->exists();
    }

    /**
     * @return Builder<Approval>
     */
    private static function query(Model $approvable, Model $actor): Builder
    {
        $model = ApprovalModelResolver::class();

        return $model::query()
            ->whereMorphedTo('approvable', $approvable)
            ->whereMorphedTo('actor', $actor);
    }
}
