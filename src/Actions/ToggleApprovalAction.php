<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\LiveDecisions;

final class ToggleApprovalAction
{
    use AuthorizesDecisions;
    use ResolvesApproval;

    public function __construct(
        private readonly ApproveAction $approve,
    ) {}

    /**
     * Toggle the actor's approval of the approvable. With no live approval in the slot
     * (the approvable's open request, or standalone) it approves — through the same path
     * as approve(), so the gate, delegation and the request's rule all apply, and a
     * held rejection is superseded. With a live approval it withdraws and soft-deletes
     * it. Either way a closed round refuses it (ClosedApprovalRequestException).
     *
     * @return bool true when the approval was created, false when it was removed
     */
    public function execute(Model $actor, Model $approvable): bool
    {
        $this->authorizeDecision($actor, $approvable);

        $target = $this->decisionTarget($actor, $approvable, null);

        $this->lapseOverdueDecision($target);

        $live = app(LiveDecisions::class)->in($target);

        if ($live instanceof Approval && $live->status === ApprovalStatus::Approved) {
            $this->writeInSlot(function () use ($target, $live): void {
                $this->ensureRoundStillOpen($target->request);

                $live->cancel();
                $live->delete();
            });

            ApprovalStatusChanged::dispatch($live, ApprovalStatus::Approved, ApprovalStatus::Cancelled, $target->actor);
            ApprovalToggled::dispatch($actor, $approvable, false);

            return false;
        }

        $this->approve->execute($actor, $approvable);

        ApprovalToggled::dispatch($actor, $approvable, true);

        return true;
    }
}
