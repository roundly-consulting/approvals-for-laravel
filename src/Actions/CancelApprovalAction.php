<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

final class CancelApprovalAction
{
    use AuthorizesDecisions;
    use ResolvesApproval;

    /**
     * Withdraw the actor's active approval of the approvable, if one exists.
     */
    public function execute(Model $actor, Model $approvable, ?string $reason = null): ?Approval
    {
        // Withdrawing a decision changes the outcome as much as making one.
        $this->authorizeDecision($actor, $approvable);

        $model = ApprovalModelResolver::class();

        $approval = $model::query()
            ->whereMorphedTo('actor', $actor)
            ->whereMorphedTo('approvable', $approvable)
            ->active()
            ->latest('id')
            ->first();

        if (! $approval instanceof Approval || $approval->status === ApprovalStatus::Cancelled) {
            return null;
        }

        $from = $approval->status;

        $approval->cancel($reason);

        ApprovalCancelled::dispatch($approval);
        ApprovalStatusChanged::dispatch($approval, $from, ApprovalStatus::Cancelled, $actor);

        return $approval;
    }
}
