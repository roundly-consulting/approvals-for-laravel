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
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\IncompletePendingApprovalException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

final class PendingApproval
{
    private ?Model $actor = null;

    private ?Model $approvable = null;

    private ?string $reason = null;

    private ?CarbonInterface $expiresAt = null;

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

    public function because(string $reason): self
    {
        $this->reason = $reason;

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
        return app(ApproveAction::class)->execute(
            $this->actor(),
            $this->approvable(),
            DecisionData::approved($this->reason, $this->expiresAt),
        );
    }

    public function reject(): Approval
    {
        return app(RejectAction::class)->execute(
            $this->actor(),
            $this->approvable(),
            DecisionData::rejected($this->reason),
        );
    }

    public function request(): Approval
    {
        return app(RequestApprovalAction::class)->execute(
            $this->actor(),
            $this->approvable(),
            DecisionData::pending($this->expiresAt),
        );
    }

    public function cancel(): ?Approval
    {
        return app(CancelApprovalAction::class)->execute(
            $this->actor(),
            $this->approvable(),
            $this->reason,
        );
    }

    public function toggle(): bool
    {
        return app(ToggleApprovalAction::class)->execute($this->actor(), $this->approvable());
    }

    /**
     * Switch to opening a request from a named workflow preset for the approvable
     * (treated as the request subject).
     */
    public function workflow(string $name): PendingWorkflowRequest
    {
        return new PendingWorkflowRequest($this->approvable(), $name);
    }

    public function isApproved(): bool
    {
        $model = ApprovalModelResolver::class();

        return $model::query()
            ->whereMorphedTo('actor', $this->actor())
            ->whereMorphedTo('approvable', $this->approvable())
            ->where('status', ApprovalStatus::Approved)
            ->exists();
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
