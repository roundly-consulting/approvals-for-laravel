<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

interface RequiresApprovalInterface
{
    /**
     * @return MorphMany<ApprovalRequest, Model>
     */
    public function approvalRequests(): MorphMany;

    /**
     * @param  list<Model>  $approvers
     */
    public function requestApproval(array $approvers, ApprovalRule $rule = ApprovalRule::Unanimous, ?int $quorum = null): ApprovalRequest;

    public function currentApprovalStatus(): ApprovalStatus;

    public function isApproved(): bool;

    public function isPendingApproval(): bool;
}
