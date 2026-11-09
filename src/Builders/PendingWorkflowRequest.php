<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\OpenWorkflowRequestAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Opens an approval request from a named workflow preset:
 * `Approvals::request($invoice)->workflow('purchase')->expiresIn(3600)->open([$a, $b])`.
 *
 * An expiry set here, or on the request builder before `workflow()`, replaces the
 * preset's own `expiry` for this round; without one the round keeps the preset's.
 * The expiry is the only request-builder setting that carries over: the preset
 * defines the rest, so `workflow()` refuses them (approvers go to `open()`).
 */
final class PendingWorkflowRequest
{
    /**
     * @param  list<Model>  $excluded
     */
    public function __construct(
        private readonly ApprovalsManager $manager,
        private readonly Model $subject,
        private readonly string $workflow,
        private ?CarbonInterface $expiresAt = null,
        private array $excluded = [],
    ) {}

    /**
     * Exclude these actors from deciding the round (the requester, say), as the request
     * builder's excluding() does. Replaces any list set before workflow().
     *
     * @param  list<Model>  $actors
     */
    public function excluding(array $actors): self
    {
        $this->excluded = $actors;

        return $this;
    }

    /**
     * Give the round this deadline instead of the preset's own `expiry`.
     */
    public function expiringAt(CarbonInterface $at): self
    {
        $this->expiresAt = $at;

        return $this;
    }

    /**
     * Give the round a deadline `$seconds` from now instead of the preset's own `expiry`.
     */
    public function expiresIn(int $seconds): self
    {
        $this->expiresAt = CarbonImmutable::now()->addSeconds($seconds);

        return $this;
    }

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
        $expiresAt = $this->expiresAt;
        $excluded = $this->excluded;

        return $this->manager->perform(
            ApprovalOperation::Open,
            OpenWorkflowRequestAction::class,
            fn (OpenWorkflowRequestAction $action): ApprovalRequest => $action->execute($this->subject, $preset, $approvers, $expiresAt, $excluded),
            [
                'subject' => $this->subject,
                'staged' => $preset->isStaged(),
                'workflow' => $this->workflow,
                'excluded' => $excluded,
            ],
        );
    }
}
