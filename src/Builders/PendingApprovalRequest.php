<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\OpenApprovalRequestAction;
use RoundlyConsulting\Approvals\Actions\RequestStagedApprovalAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Opens a multi-approver request for a subject:
 *
 *     Approvals::request($invoice)->from([$a, $b])->quorum(2)->open();
 *     Approvals::request($invoice)->stages([...])->continueOnRejection()->open();
 *     Approvals::request($invoice)->workflow('purchase')->open([$a, [$b, $c]]);
 *     Approvals::request($invoice)->expiresIn(3600)->workflow('purchase')->open([$a, $b]);
 */
final class PendingApprovalRequest
{
    /** @var list<Model> */
    private array $approvers = [];

    /** @var list<StageDefinition> */
    private array $stages = [];

    private ApprovalRule $rule = ApprovalRule::Unanimous;

    private ?int $quorum = null;

    private bool $rejectOnStageRejection = true;

    private ?CarbonInterface $expiresAt = null;

    public function __construct(
        private readonly ApprovalsManager $manager,
        private readonly Model $subject,
    ) {}

    /**
     * The approvers of a flat (single-stage) request.
     *
     * @param  list<Model>  $approvers
     */
    public function from(array $approvers): self
    {
        $this->approvers = $approvers;

        return $this;
    }

    /**
     * The resolution rule of a flat request; `$quorum` is the threshold for the
     * quorum and weighted rules.
     */
    public function rule(ApprovalRule $rule, ?int $quorum = null): self
    {
        $this->rule = $rule;
        $this->quorum = $quorum;

        return $this;
    }

    /**
     * Resolve on the first approval.
     */
    public function any(): self
    {
        return $this->rule(ApprovalRule::Any);
    }

    /**
     * Resolve once `$quorum` approvals are in.
     */
    public function quorum(int $quorum): self
    {
        return $this->rule(ApprovalRule::Quorum, $quorum);
    }

    /**
     * Resolve once the summed approver weight reaches `$threshold`.
     */
    public function weighted(int $threshold): self
    {
        return $this->rule(ApprovalRule::Weighted, $threshold);
    }

    /**
     * Make the request staged: stage N opens once stage N-1 has cleared.
     *
     * @param  list<StageDefinition>  $stages
     */
    public function stages(array $stages): self
    {
        $this->stages = $stages;

        return $this;
    }

    /**
     * Keep a staged request going when a stage is rejected (by default a rejected
     * stage rejects the whole request).
     */
    public function continueOnRejection(bool $continue = true): self
    {
        $this->rejectOnStageRejection = ! $continue;

        return $this;
    }

    public function expiringAt(CarbonInterface $at): self
    {
        $this->expiresAt = $at;

        return $this;
    }

    public function expiresIn(int $seconds): self
    {
        $this->expiresAt = CarbonImmutable::now()->addSeconds($seconds);

        return $this;
    }

    /**
     * Open the request from a named workflow preset instead; its approvers go to open().
     * An expiry already set here carries over and replaces the preset's own `expiry`.
     */
    public function workflow(string $name): PendingWorkflowRequest
    {
        return new PendingWorkflowRequest($this->manager, $this->subject, $name, $this->expiresAt);
    }

    public function open(): ApprovalRequest
    {
        if ($this->stages !== [] && $this->approvers !== []) {
            throw InvalidApprovalRequestException::mixedApprovers();
        }

        $context = [
            'subject' => $this->subject,
            'staged' => $this->stages !== [],
            'workflow' => null,
        ];

        if ($this->stages !== []) {
            $stages = $this->stages;

            return $this->manager->perform(
                ApprovalOperation::Open,
                RequestStagedApprovalAction::class,
                fn (RequestStagedApprovalAction $action): ApprovalRequest => $action->execute(
                    $this->subject,
                    $stages,
                    $this->rejectOnStageRejection,
                    $this->expiresAt,
                ),
                $context,
            );
        }

        $data = new ApprovalRequestData(
            subject: $this->subject,
            approvers: $this->approvers,
            rule: $this->rule,
            quorum: $this->quorum,
            expiresAt: $this->expiresAt,
        );

        return $this->manager->perform(
            ApprovalOperation::Open,
            OpenApprovalRequestAction::class,
            static fn (OpenApprovalRequestAction $action): ApprovalRequest => $action->execute($data),
            $context,
        );
    }
}
