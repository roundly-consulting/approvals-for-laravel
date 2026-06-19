<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use Carbon\CarbonInterface;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

final readonly class DecisionData
{
    public function __construct(
        public ApprovalStatus $status,
        public ?string $reason = null,
        public ?CarbonInterface $expiresAt = null,
        public ?CarbonInterface $decidedAt = null,
        public ?int $weight = null,
    ) {}

    public static function approved(?string $reason = null, ?CarbonInterface $expiresAt = null, ?int $weight = null): self
    {
        return new self(ApprovalStatus::Approved, $reason, $expiresAt, weight: $weight);
    }

    public static function rejected(?string $reason = null): self
    {
        return new self(ApprovalStatus::Rejected, $reason);
    }

    public static function pending(?CarbonInterface $expiresAt = null): self
    {
        return new self(ApprovalStatus::Pending, expiresAt: $expiresAt);
    }

    public static function cancelled(?string $reason = null): self
    {
        return new self(ApprovalStatus::Cancelled, $reason);
    }
}
