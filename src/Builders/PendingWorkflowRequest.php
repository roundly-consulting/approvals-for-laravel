<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\OpenWorkflowRequestAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Opens an approval request from a named workflow preset:
 * `Approvals::request($invoice)->workflow('purchase')->open([$a, $b])`.
 */
final readonly class PendingWorkflowRequest
{
    public function __construct(
        private ApprovalsManager $manager,
        private Model $subject,
        private string $workflow,
    ) {}

    /**
     * Open the request, supplying the approvers.
     *
     * For a flat preset pass a flat list of approvers. For a staged preset pass one
     * approver list per stage, in stage order.
     *
     * @param  array<int, Model|list<Model>>  $approvers
     */
    public function open(array $approvers = []): ApprovalRequest
    {
        $preset = $this->manager->preset($this->workflow);

        return $this->manager->perform(
            ApprovalOperation::Open,
            OpenWorkflowRequestAction::class,
            fn (OpenWorkflowRequestAction $action): ApprovalRequest => $action->execute($this->subject, $preset, $approvers),
            [
                'subject' => $this->subject,
                'staged' => $preset->isStaged(),
                'workflow' => $this->workflow,
            ],
        );
    }
}
