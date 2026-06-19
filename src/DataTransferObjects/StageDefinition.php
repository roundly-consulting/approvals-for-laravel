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
     * @param  list<Model>  $approvers
     */
    public function __construct(
        public array $approvers,
        public ApprovalRule $rule = ApprovalRule::Unanimous,
        public ?int $quorum = null,
        public ?string $name = null,
    ) {}
}
