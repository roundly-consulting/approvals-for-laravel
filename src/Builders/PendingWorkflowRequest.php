<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\OpenWorkflowRequestAction;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\WorkflowResolver;

/**
 * Fluent builder for opening an approval request from a named workflow preset.
 */
final class PendingWorkflowRequest
{
    public function __construct(
        private readonly Model $subject,
        private readonly string $workflow,
    ) {}

    /**
     * Open the request, supplying the approvers.
     *
     * For a flat preset pass a flat list of approvers. For a staged preset pass one
     * approver list per stage, in stage order.
     *
     * @param  list<Model>|list<list<Model>>  $approvers
     */
    public function request(array $approvers = []): ApprovalRequest
    {
        $preset = app(WorkflowResolver::class)->resolve($this->workflow);

        return app(OpenWorkflowRequestAction::class)->execute($this->subject, $preset, $approvers);
    }
}
