<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AuthorizesDecisions;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
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
     * (the pinned request, else the approvable's open request, else standalone) it
     * approves — through the same path as approve(), so the gate, delegation and the
     * request's rule all apply, and a held rejection is superseded. With a live approval
     * it withdraws and soft-deletes it, firing ApprovalCancelled like cancel() does.
     * Either way a closed round refuses it (ClosedApprovalRequestException), and a
     * pinned request of another subject is refused (InvalidApprovalRequestException).
     *
     * `$data` shapes the approval toggling on records (its reason, expiry and weight, as
     * for approve()); toggling off records no decision, so only its reason is used, as
     * the withdrawal's reason (as for cancel()).
     *
     * @return bool true when the approval was created, false when it was removed
     */
    public function execute(
        Model $actor,
        Model $approvable,
        ?DecisionData $data = null,
        ?ApprovalRequest $request = null,
    ): bool {
        $this->authorizeDecision($actor, $approvable);

        $data ??= DecisionData::approved();

        $target = $this->decisionTarget($actor, $approvable, $request);

        $this->lapseOverdueDecision($target);

        $live = app(LiveDecisions::class)->in($target);

        if ($live instanceof Approval && $live->status === ApprovalStatus::Approved) {
            $this->writeInSlot(function () use ($target, $live, $data): void {
                $this->ensureRoundStillOpen($target->request);

                $live->cancel($data->reason);
                $live->delete();
            });

            // Toggling off withdraws the approval, so it announces it as cancel() does.
            ApprovalCancelled::dispatch($live);
            ApprovalStatusChanged::dispatch($live, ApprovalStatus::Approved, ApprovalStatus::Cancelled, $target->actor);
            ApprovalToggled::dispatch($actor, $approvable, false);

            return false;
        }

        $this->approve->execute($actor, $approvable, $data, $request);

        ApprovalToggled::dispatch($actor, $approvable, true);

        return true;
    }
}
