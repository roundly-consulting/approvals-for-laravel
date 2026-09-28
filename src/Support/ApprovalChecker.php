<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalProgress;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

/**
 * Status checks that work against any approvable or subject model without depending
 * on the HasApprovals / RequiresApproval traits being present. Used by the manager's
 * read methods, the builders and the Blade directives.
 *
 * @internal
 */
final class ApprovalChecker
{
    /**
     * Whether `$actor` holds an approval of the approvable that is still in force (a
     * decision past its expiry stops counting at once, before the sweep lapses it).
     */
    public static function isApprovedBy(Model $approvable, Model $actor): bool
    {
        return self::decisionsBy($approvable, $actor)
            ->where('status', ApprovalStatus::Approved)
            ->inForce()
            ->exists();
    }

    public static function isRejectedBy(Model $approvable, Model $actor): bool
    {
        return self::decisionsBy($approvable, $actor)
            ->where('status', ApprovalStatus::Rejected)
            ->inForce()
            ->exists();
    }

    public static function hasPending(Model $approvable): bool
    {
        $model = ApprovalModelResolver::class();

        return $model::query()
            ->whereMorphedTo('approvable', $approvable)
            ->where('status', ApprovalStatus::Pending)
            ->inForce()
            ->exists();
    }

    /**
     * The subject's most recent approval request, if it has one.
     */
    public static function latestRequest(Model $subject): ?ApprovalRequest
    {
        $model = ApprovalRequestModelResolver::class();

        $request = $model::query()
            ->whereMorphedTo('subject', $subject)
            ->latest('id')
            ->first();

        return $request instanceof ApprovalRequest ? $request : null;
    }

    /**
     * The status of the subject's latest request; `Pending` when it has none, and
     * `Expired` once a pending request's expiry has passed (before the sweep lapses it).
     */
    public static function status(Model $subject): ApprovalStatus
    {
        $request = self::latestRequest($subject);

        if (! $request instanceof ApprovalRequest) {
            return ApprovalStatus::Pending;
        }

        return $request->isOverdue() ? ApprovalStatus::Expired : $request->status;
    }

    public static function progress(Model $subject): ?ApprovalProgress
    {
        return self::latestRequest($subject)?->approvalProgress();
    }

    public static function currentStage(Model $subject): ?ApprovalRequestStage
    {
        return self::latestRequest($subject)?->currentStage();
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
