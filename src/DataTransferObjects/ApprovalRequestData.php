<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;

final readonly class ApprovalRequestData
{
    /**
     * @param  list<Model>  $approvers
     * @param  int|null  $requiredApprovers  the headcount (or weight) in play; defaults to count($approvers)
     * @param  string|null  $workflow  the preset the request was opened from, if any
     */
    public function __construct(
        public Model $subject,
        public array $approvers,
        public ApprovalRule $rule = ApprovalRule::Unanimous,
        public ?int $quorum = null,
        public ?CarbonInterface $expiresAt = null,
        public ?int $requiredApprovers = null,
        public ?string $workflow = null,
    ) {}
}
