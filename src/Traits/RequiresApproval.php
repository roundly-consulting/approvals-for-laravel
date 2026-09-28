<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Traits;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalProgress;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * Marks a model as a subject that needs sign-off from one or more approvers. Every
 * method goes through {@see ApprovalsManager}, so `Approvals::fake()` records the
 * requests it opens.
 *
 * @mixin Model
 */
trait RequiresApproval
{
    /**
     * @return MorphMany<ApprovalRequest, $this>
     */
    public function approvalRequests(): MorphMany
    {
        return $this->morphMany(ApprovalRequestModelResolver::class(), 'subject');
    }

    /**
     * Open a new approval request requiring sign-off from the given approvers.
     *
     * @param  list<Model>  $approvers
     */
    public function requestApproval(
        array $approvers,
        ApprovalRule $rule = ApprovalRule::Unanimous,
        ?int $quorum = null,
    ): ApprovalRequest {
        return app(ApprovalsManager::class)
            ->request($this)
            ->from($approvers)
            ->rule($rule, $quorum)
            ->open();
    }

    /**
     * Open a sequential, multi-stage approval pipeline. Stage N only opens once stage
     * N-1 has cleared; a rejection rejects the request unless $rejectOnStageRejection
     * is false.
     *
     * @param  list<StageDefinition>  $stages
     */
    public function requestStagedApproval(
        array $stages,
        bool $rejectOnStageRejection = true,
        ?CarbonInterface $expiresAt = null,
    ): ApprovalRequest {
        $pending = app(ApprovalsManager::class)
            ->request($this)
            ->stages($stages)
            ->continueOnRejection(! $rejectOnStageRejection);

        if ($expiresAt !== null) {
            $pending->expiringAt($expiresAt);
        }

        return $pending->open();
    }

    /**
     * The stage currently open for decisions on the latest staged request, if any.
     */
    public function currentStage(): ?ApprovalRequestStage
    {
        return app(ApprovalsManager::class)->currentStage($this);
    }

    /**
     * A snapshot of how far the latest approval request has progressed.
     */
    public function approvalProgress(): ?ApprovalProgress
    {
        return app(ApprovalsManager::class)->progress($this);
    }

    public function currentApprovalStatus(): ApprovalStatus
    {
        return app(ApprovalsManager::class)->status($this);
    }

    public function isApproved(): bool
    {
        return $this->currentApprovalStatus() === ApprovalStatus::Approved;
    }

    public function isPendingApproval(): bool
    {
        return $this->currentApprovalStatus() === ApprovalStatus::Pending;
    }
}
