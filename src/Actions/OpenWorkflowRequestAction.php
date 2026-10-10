<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Opens an approval request from a resolved workflow preset.
 *
 * For a flat preset, $approvers is a flat list of approvers. For a staged preset,
 * $approvers is a list of approver lists, one per stage, in the preset's stage order.
 *
 * The round gets one deadline, `$expiresAt` when given, else the preset's `expiry`
 * seconds from now (or none). A staged round shares it across every stage.
 */
final readonly class OpenWorkflowRequestAction
{
    public function __construct(
        private OpenApprovalRequestAction $openRequest,
        private RequestStagedApprovalAction $openStagedRequest,
    ) {}

    /**
     * @param  array<int, Model|list<Model>>  $approvers
     * @param  CarbonInterface|null  $expiresAt  the round's deadline; null keeps the preset's own `expiry`
     */
    public function execute(Model $subject, WorkflowPreset $preset, array $approvers, ?CarbonInterface $expiresAt = null): ApprovalRequest
    {
        $expiresAt ??= $preset->expiry === null ? null : CarbonImmutable::now()->addSeconds($preset->expiry);

        return $preset->isStaged()
            ? $this->openStaged($subject, $preset, $approvers, $expiresAt)
            : $this->openFlat($subject, $preset, $approvers, $expiresAt);
    }

    /**
     * @param  array<int, Model|list<Model>>  $approvers
     */
    private function openFlat(Model $subject, WorkflowPreset $preset, array $approvers, ?CarbonInterface $expiresAt): ApprovalRequest
    {
        $flat = $this->flatten($approvers);

        return $this->openRequest->execute(new ApprovalRequestData(
            subject: $subject,
            approvers: $flat,
            rule: $preset->rule,
            quorum: $preset->quorum,
            expiresAt: $expiresAt,
            requiredApprovers: $preset->requiredApprovers,
            workflow: $preset->name,
        ));
    }

    /**
     * @param  array<int, Model|list<Model>>  $approvers
     */
    private function openStaged(Model $subject, WorkflowPreset $preset, array $approvers, ?CarbonInterface $expiresAt): ApprovalRequest
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
                requiredApprovers: $stage->requiredApprovers,
            );
        }

        return $this->openStagedRequest->execute(
            $subject,
            $definitions,
            $preset->rejectOnStageRejection,
            $expiresAt,
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
