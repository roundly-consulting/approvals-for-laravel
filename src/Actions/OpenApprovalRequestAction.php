<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApproverList;
use RoundlyConsulting\Approvals\Support\ExcludedApprovers;
use RoundlyConsulting\Approvals\Support\SubjectFingerprint;

/**
 * Opens a flat (single-stage) approval request for a subject. Decisions recorded
 * against the subject count towards it until its rule resolves it. The approvers it
 * names are stored, and only they (or their delegates) may decide it; a request opened
 * without names may be decided by any approver.
 *
 * The actors it excludes, and those the configured exclusion resolver names, may never
 * decide it, so naming one of them as an approver is refused. When the subject provides
 * an approval fingerprint, the round stores it, to tell later whether the subject changed.
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
        $excluded = ApproverList::excluded($data->excluded);

        ApproverList::ensureNoneExcluded($named, [...$excluded, ...app(ExcludedApprovers::class)->for($data->subject, null)]);

        $request->quorum = $data->quorum;
        $request->approvers = ApproverList::payload($named);
        $request->required_approvers = ApproverList::required($named, $data->requiredApprovers);

        ApproverList::ensureReachable($data->rule, $data->quorum, $request->required_approvers, $named);
        $request->status = ApprovalStatus::Pending;
        $request->workflow = $data->workflow;
        $this->stampGuards($request, $excluded, $data);
        $request->expires_at = $data->expiresAt === null
            ? null
            : CarbonImmutable::instance($data->expiresAt->toDateTimeImmutable());
        $request->save();

        return $request;
    }

    /**
     * Only columns with a value are written, so a host that has not run the 2.2.0
     * migration keeps opening rounds until it uses the guards.
     *
     * @param  list<NamedApprover>  $excluded
     */
    private function stampGuards(ApprovalRequest $request, array $excluded, ApprovalRequestData $data): void
    {
        if ($excluded !== []) {
            $request->excluded_approvers = ApproverList::exclusionPayload($excluded);
        }

        $fingerprint = SubjectFingerprint::current($data->subject);

        if ($fingerprint !== null) {
            $request->subject_fingerprint = $fingerprint;
        }
    }
}
