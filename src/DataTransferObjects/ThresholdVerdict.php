<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

/**
 * Whether an open round can still reach its threshold with the approvers who can still
 * decide — the answer of `Approvals::for($subject)->recheckThreshold()`.
 */
final readonly class ThresholdVerdict
{
    /**
     * @param  bool  $reachable  whether the decisions in, plus those the approvers who can still decide could add, can still approve it
     * @param  list<NamedApprover>  $unavailable  the outstanding named approvers who can no longer decide
     * @param  ApprovalRequestStage|null  $stage  the first stage that can no longer clear, on a staged round
     * @param  ApprovalStatus|null  $closedAs  how the re-check closed the round (cancelled or rejected), null when it left it open
     */
    public function __construct(
        public ApprovalRequest $request,
        public bool $reachable,
        public array $unavailable = [],
        public ?ApprovalRequestStage $stage = null,
        public ?ApprovalStatus $closedAs = null,
    ) {}

    public function withClosedAs(ApprovalStatus $outcome): self
    {
        return new self($this->request, $this->reachable, $this->unavailable, $this->stage, $outcome);
    }
}
