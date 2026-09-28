<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApproverList;

/**
 * Opens a flat (single-stage) approval request for a subject. Decisions recorded
 * against the subject count towards it until its rule resolves it. The approvers it
 * names are stored, and only they (or their delegates) may decide it; a request opened
 * without names may be decided by any approver.
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
        $named = ApproverList::name($data->approvers, $data->subject);

        $request->quorum = $data->quorum;
        $request->approvers = ApproverList::payload($named);
        $request->required_approvers = ApproverList::required($named, $data->requiredApprovers);
        $request->status = ApprovalStatus::Pending;
        $request->workflow = $data->workflow;
        $request->expires_at = $data->expiresAt === null
            ? null
            : CarbonImmutable::instance($data->expiresAt->toDateTimeImmutable());
        $request->save();

        return $request;
    }
}
