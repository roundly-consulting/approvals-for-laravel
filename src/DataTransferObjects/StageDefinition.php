<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;

/**
 * Defines a single stage of a sequential approval pipeline.
 */
final readonly class StageDefinition
{
    /**
     * @param  list<Model>  $approvers  the stage's named approvers; only they may decide it (none named: anyone may)
     * @param  int|null  $requiredApprovers  the approvals the stage needs; defaults to one per named approver
     */
    public function __construct(
        public array $approvers,
        public ApprovalRule $rule = ApprovalRule::Unanimous,
        public ?int $quorum = null,
        public ?string $name = null,
        public ?int $requiredApprovers = null,
    ) {}
}
