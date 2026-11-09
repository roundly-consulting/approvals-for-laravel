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
 *     Approvals::request($invoice)->excluding([$requester])->from([$a, $b])->open();
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

    /** @var list<Model> */
    private array $excluded = [];

    /**
     * The settings made here that a workflow preset defines itself, by method name in
     * call order: `workflow()` refuses them rather than dropping them.
     *
     * @var array<string, true>
     */
    private array $presetDefined = [];

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
        $this->presetDefined['from()'] = true;

        return $this;
    }

    /**
     * The resolution rule of a flat request; `$quorum` is the threshold for the
     * quorum and weighted rules.
     */
    public function rule(ApprovalRule $rule, ?int $quorum = null): self
    {
        return $this->setRule($rule, $quorum, 'rule()');
    }

    /**
     * Resolve on the first approval.
     */
    public function any(): self
    {
        return $this->setRule(ApprovalRule::Any, null, 'any()');
    }

    /**
     * Resolve once `$quorum` approvals are in.
     */
    public function quorum(int $quorum): self
    {
        return $this->setRule(ApprovalRule::Quorum, $quorum, 'quorum()');
    }

    /**
     * Resolve once the summed approver weight reaches `$threshold`.
     */
    public function weighted(int $threshold): self
    {
        return $this->setRule(ApprovalRule::Weighted, $threshold, 'weighted()');
    }

    /**
     * Make the request staged: stage N opens once stage N-1 has cleared.
     *
     * @param  list<StageDefinition>  $stages
     */
    public function stages(array $stages): self
    {
        $this->stages = $stages;
        $this->presetDefined['stages()'] = true;

        return $this;
    }

    /**
     * Keep a staged request going when a stage is rejected (by default a rejected
     * stage rejects the whole request).
     */
    public function continueOnRejection(bool $continue = true): self
    {
        $this->rejectOnStageRejection = ! $continue;
        $this->presetDefined['continueOnRejection()'] = true;

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
     * Exclude these actors from deciding the round: the requester, say. An excluded actor
     * can neither decide nor be asked, cannot act as anyone's delegate, and no delegate
     * can decide on its behalf; naming one as an approver is refused. Carries over to
     * workflow(). Each call replaces the list.
     *
     * @param  list<Model>  $actors
     */
    public function excluding(array $actors): self
    {
        $this->excluded = $actors;

        return $this;
    }

    /**
     * Open the request from a named workflow preset instead; its approvers go to open().
     * An expiry already set here carries over and replaces the preset's own `expiry`, and
     * so do the actors excluded with excluding().
     *
     * The preset defines the rule, quorum, stages and stage rejection itself, so a call
     * to from(), rule(), any(), quorum(), weighted(), stages() or continueOnRejection()
     * before this is refused rather than silently dropped.
     *
     * @throws InvalidApprovalRequestException when such a setting was made first
     */
    public function workflow(string $name): PendingWorkflowRequest
    {
        if ($this->presetDefined !== []) {
            throw InvalidApprovalRequestException::definedByWorkflow($name, array_keys($this->presetDefined));
        }

        return new PendingWorkflowRequest($this->manager, $this->subject, $name, $this->expiresAt, $this->excluded);
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
            'excluded' => $this->excluded,
        ];

        $excluded = $this->excluded;

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
                    null,
                    $excluded,
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
            excluded: $excluded,
        );

        return $this->manager->perform(
            ApprovalOperation::Open,
            OpenApprovalRequestAction::class,
            static fn (OpenApprovalRequestAction $action): ApprovalRequest => $action->execute($data),
            $context,
        );
    }

    private function setRule(ApprovalRule $rule, ?int $quorum, string $setting): self
    {
        $this->rule = $rule;
        $this->quorum = $quorum;
        $this->presetDefined[$setting] = true;

        return $this;
    }
}
