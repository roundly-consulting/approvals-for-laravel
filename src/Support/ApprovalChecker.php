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
        return self::decisionsBy($approvable, $actor)
            ->where('status', ApprovalStatus::Approved)
            ->exists();
    }

    public static function isRejectedBy(Model $approvable, Model $actor): bool
    {
        return self::decisionsBy($approvable, $actor)
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
     * The decisions `$actor` has recorded against `$approvable`, resolved through the
     * configured-model seam.
     *
     * Named `decisionsBy()` rather than `query()`: this class is not an Eloquent model,
     * so `self::query()` here was a call to *this* helper — but it read exactly like the
     * `Model::query()` late-static-binding bypass that ignores a host's model swap
     * (permissions #34), and the arch pin cannot tell the two apart from tokens alone.
     * The name now says which one it is.
     *
     * @return Builder<Approval>
     */
    private static function decisionsBy(Model $approvable, Model $actor): Builder
    {
        $model = ApprovalModelResolver::class();

        return $model::query()
            ->whereMorphedTo('approvable', $approvable)
            ->whereMorphedTo('actor', $actor);
    }
}
