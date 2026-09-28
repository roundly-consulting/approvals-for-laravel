<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\CancelApprovalAction;
use RoundlyConsulting\Approvals\Actions\RejectAction;
use RoundlyConsulting\Approvals\Actions\RequestApprovalAction;
use RoundlyConsulting\Approvals\Actions\ToggleApprovalAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Exceptions\IncompletePendingApprovalException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;

/**
 * One actor's decision on one approvable: `Approvals::for($invoice)->as($user)->approve()`.
 */
final class PendingApproval
{
    private ?Model $actor = null;

    private ?Model $approvable = null;

    private ?ApprovalRequest $request = null;

    private ?string $reason = null;

    private ?int $weight = null;

    private ?CarbonInterface $expiresAt = null;

    public function __construct(
        private readonly ApprovalsManager $manager,
    ) {}

    public function as(Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function for(Model $approvable): self
    {
        $this->approvable = $approvable;

        return $this;
    }

    /**
     * Pin the decision to this request instead of the approvable's latest open one.
     * The request must belong to the approvable.
     */
    public function within(ApprovalRequest $request): self
    {
        $this->request = $request;

        return $this;
    }

    public function because(?string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    /**
     * Override the weight this decision carries towards a quorum/weighted threshold.
     */
    public function weight(int $weight): self
    {
        $this->weight = $weight;

        return $this;
    }

    public function expiringAt(CarbonInterface $at): self
    {
        $this->expiresAt = $at;

        return $this;
    }

    public function expiresIn(int $seconds): self
    {
        $this->expiresAt = CarbonImmutable::now()->addSeconds($seconds);

        return $this;
    }

    public function approve(): Approval
    {
        $actor = $this->actor();
        $approvable = $this->approvable();
        $data = DecisionData::approved($this->reason, $this->expiresAt, $this->weight);

        return $this->manager->perform(
            ApprovalOperation::Approve,
            ApproveAction::class,
            fn (ApproveAction $action): Approval => $action->execute($actor, $approvable, $data, $this->request),
            $this->context($actor, $approvable),
        );
    }

    public function reject(): Approval
    {
        $actor = $this->actor();
        $approvable = $this->approvable();
        $data = DecisionData::rejected($this->reason, $this->weight);

        return $this->manager->perform(
            ApprovalOperation::Reject,
            RejectAction::class,
            fn (RejectAction $action): Approval => $action->execute($actor, $approvable, $data, $this->request),
            $this->context($actor, $approvable),
        );
    }

    /**
     * Ask the actor for a decision: record a pending approval for the pair.
     */
    public function ask(): Approval
    {
        $actor = $this->actor();
        $approvable = $this->approvable();
        $data = DecisionData::pending($this->expiresAt);

        return $this->manager->perform(
            ApprovalOperation::Ask,
            RequestApprovalAction::class,
            fn (RequestApprovalAction $action): Approval => $action->execute($actor, $approvable, $data, $this->request),
            $this->context($actor, $approvable),
        );
    }

    /**
     * Withdraw the actor's live decision (pending, approved or rejected), or one it made
     * as a delegate, if there is one — within the pinned request when within() was used.
     */
    public function cancel(): ?Approval
    {
        $actor = $this->actor();
        $approvable = $this->approvable();

        return $this->manager->perform(
            ApprovalOperation::Cancel,
            CancelApprovalAction::class,
            fn (CancelApprovalAction $action): ?Approval => $action->execute($actor, $approvable, $this->reason, $this->request),
            $this->context($actor, $approvable),
        );
    }

    /**
     * @return bool true when the approval was created, false when it was removed
     */
    public function toggle(): bool
    {
        $actor = $this->actor();
        $approvable = $this->approvable();

        return $this->manager->perform(
            ApprovalOperation::Toggle,
            ToggleApprovalAction::class,
            static fn (ToggleApprovalAction $action): bool => $action->execute($actor, $approvable),
            $this->context($actor, $approvable),
        );
    }

    public function isApproved(): bool
    {
        return ApprovalChecker::isApprovedBy($this->approvable(), $this->actor());
    }

    public function isRejected(): bool
    {
        return ApprovalChecker::isRejectedBy($this->approvable(), $this->actor());
    }

    /**
     * Whether the approvable has any pending decision (from any actor).
     */
    public function hasPending(): bool
    {
        return ApprovalChecker::hasPending($this->approvable());
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Model $actor, Model $approvable): array
    {
        return [
            'actor' => $actor,
            'approvable' => $approvable,
            'request' => $this->request,
            'reason' => $this->reason,
        ];
    }

    private function actor(): Model
    {
        return $this->actor ?? throw IncompletePendingApprovalException::missingActor();
    }

    private function approvable(): Model
    {
        return $this->approvable ?? throw IncompletePendingApprovalException::missingApprovable();
    }
}
