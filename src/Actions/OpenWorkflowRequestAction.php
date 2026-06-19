<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * Opens an approval request from a resolved workflow preset.
 *
 * For a flat preset, $approvers is a flat list of approvers. For a staged preset,
 * $approvers is a list of approver lists, one per stage, in the preset's stage order.
 */
final class OpenWorkflowRequestAction
{
    /**
     * @param  array<int, Model|list<Model>>  $approvers
     */
    public function execute(Model $subject, WorkflowPreset $preset, array $approvers): ApprovalRequest
    {
        return $preset->isStaged()
            ? $this->openStaged($subject, $preset, $approvers)
            : $this->openFlat($subject, $preset, $approvers);
    }

    /**
     * @param  array<int, Model|list<Model>>  $approvers
     */
    private function openFlat(Model $subject, WorkflowPreset $preset, array $approvers): ApprovalRequest
    {
        $flat = $this->flatten($approvers);

        $requestModel = ApprovalRequestModelResolver::class();

        $request = new $requestModel;
        $request->subject_id = $subject->getKey();
        $request->subject_type = $subject->getMorphClass();
        $request->rule = $preset->rule;
        $request->quorum = $preset->quorum;
        $request->required_approvers = $preset->requiredApprovers ?? count($flat);
        $request->status = ApprovalStatus::Pending;
        $request->workflow = $preset->name;
        $request->expires_at = $preset->expiry === null
            ? null
            : CarbonImmutable::now()->addSeconds($preset->expiry);
        $request->save();

        return $request;
    }

    /**
     * @param  array<int, Model|list<Model>>  $approvers
     */
    private function openStaged(Model $subject, WorkflowPreset $preset, array $approvers): ApprovalRequest
    {
        if (count($approvers) !== count($preset->stages)) {
            throw UnknownWorkflowException::invalid(
                $preset->name,
                'one approver group per stage is required for a staged workflow.',
            );
        }

        $definitions = [];

        foreach ($preset->stages as $index => $stage) {
            $group = $approvers[$index] ?? [];

            $members = is_array($group) ? $group : [$group];

            $definitions[] = new StageDefinition(
                approvers: $members,
                rule: $stage->rule,
                quorum: $stage->quorum,
                name: $stage->name,
            );
        }

        return app(RequestStagedApprovalAction::class)->execute(
            $subject,
            $definitions,
            $preset->rejectOnStageRejection,
            $preset->expiry === null ? null : CarbonImmutable::now()->addSeconds($preset->expiry),
            $preset->name,
        );
    }

    /**
     * @param  array<int, Model|list<Model>>  $approvers
     * @return list<Model>
     */
    private function flatten(array $approvers): array
    {
        $flat = [];

        foreach ($approvers as $entry) {
            if (is_array($entry)) {
                foreach ($entry as $model) {
                    $flat[] = $model;
                }

                continue;
            }

            $flat[] = $entry;
        }

        return $flat;
    }
}
