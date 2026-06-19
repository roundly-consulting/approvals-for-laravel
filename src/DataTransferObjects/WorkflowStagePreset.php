<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use RoundlyConsulting\Approvals\Enums\ApprovalRule;

final readonly class WorkflowStagePreset
{
    public function __construct(
        public ApprovalRule $rule,
        public int $requiredApprovers,
        public ?int $quorum = null,
        public ?string $name = null,
    ) {}
}
