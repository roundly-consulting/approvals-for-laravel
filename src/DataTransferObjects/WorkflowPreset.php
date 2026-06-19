<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use RoundlyConsulting\Approvals\Enums\ApprovalRule;

/**
 * A resolved approval workflow preset from config('approvals.workflows').
 *
 * A preset is either flat (a single rule/quorum) or staged (a list of stage shapes).
 */
final readonly class WorkflowPreset
{
    /**
     * @param  list<WorkflowStagePreset>  $stages
     */
    public function __construct(
        public string $name,
        public ApprovalRule $rule = ApprovalRule::Unanimous,
        public ?int $quorum = null,
        public ?int $requiredApprovers = null,
        public ?int $expiry = null,
        public array $stages = [],
        public bool $rejectOnStageRejection = true,
    ) {}

    public function isStaged(): bool
    {
        return $this->stages !== [];
    }
}
