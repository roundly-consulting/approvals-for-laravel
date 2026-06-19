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
     */
    public function __construct(
        public Model $subject,
        public array $approvers,
        public ApprovalRule $rule = ApprovalRule::Unanimous,
        public ?int $quorum = null,
        public ?CarbonInterface $expiresAt = null,
    ) {}
}
