<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * Opens a flat (single-stage) approval request for a subject. Decisions recorded
 * against the subject count towards it until its rule resolves it.
 */
final class OpenApprovalRequestAction
{
    public function execute(ApprovalRequestData $data): ApprovalRequest
    {
        $model = ApprovalRequestModelResolver::class();

        $request = new $model;
        $request->subject_id = $data->subject->getKey();
        $request->subject_type = $data->subject->getMorphClass();
        $request->rule = $data->rule;
        $request->quorum = $data->quorum;
        $request->required_approvers = $data->requiredApprovers ?? count($data->approvers);
        $request->status = ApprovalStatus::Pending;
        $request->workflow = $data->workflow;
        $request->expires_at = $data->expiresAt === null
            ? null
            : CarbonImmutable::instance($data->expiresAt->toDateTimeImmutable());
        $request->save();

        return $request;
    }
}
