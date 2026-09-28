<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestStageModelResolver;
use RoundlyConsulting\Approvals\Support\ApproverList;

final class RequestStagedApprovalAction
{
    /**
     * Open a staged (sequential) approval request for the subject. Each stage stores the
     * approvers it names, and only they (or their delegates) may decide it while it is
     * open; a stage opened without names may be decided by any approver.
     *
     * @param  list<StageDefinition>  $stages
     */
    public function execute(
        Model $subject,
        array $stages,
        bool $rejectOnStageRejection = true,
        ?CarbonInterface $expiresAt = null,
        ?string $workflow = null,
    ): ApprovalRequest {
        // Name (and validate) every stage before anything is written.
        $named = [];
        $required = [];

        foreach ($stages as $index => $definition) {
            $named[$index] = ApproverList::name($definition->approvers, $subject);
            $required[$index] = ApproverList::required($named[$index], $definition->requiredApprovers);
        }

        $requestModel = ApprovalRequestModelResolver::class();

        $request = new $requestModel;
        $request->subject_id = $subject->getKey();
        $request->subject_type = $subject->getMorphClass();
        $request->rule = ApprovalRule::Unanimous;
        $request->required_approvers = array_sum($required);
        $request->status = ApprovalStatus::Pending;
        $request->staged = true;
        $request->reject_on_stage_rejection = $rejectOnStageRejection;
        $request->workflow = $workflow;
        $request->expires_at = $expiresAt === null
            ? null
            : CarbonImmutable::instance($expiresAt->toDateTimeImmutable());
        $request->save();

        $stageModel = ApprovalRequestStageModelResolver::class();

        $position = 1;

        foreach ($stages as $index => $definition) {
            $stage = new $stageModel;
            $stage->approval_request_id = $request->getKey();
            $stage->position = $position;
            $stage->name = $definition->name;
            $stage->rule = $definition->rule;
            $stage->quorum = $definition->quorum;
            $stage->approvers = ApproverList::payload($named[$index]);
            $stage->required_approvers = $required[$index];
            $stage->status = ApprovalStatus::Pending;

            // The first stage opens immediately; later stages open as earlier ones clear.
            if ($position === 1) {
                $stage->opened_at = CarbonImmutable::now();
            }

            $stage->save();

            if ($position === 1) {
                ApprovalStageOpened::dispatch($stage);
            }

            $position++;
        }

        return $request;
    }
}
