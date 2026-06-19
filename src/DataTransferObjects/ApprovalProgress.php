<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

/**
 * A snapshot of how far an approval request has progressed.
 */
final readonly class ApprovalProgress
{
    public function __construct(
        public ApprovalStatus $status,
        public int $approved,
        public int $rejected,
        public int $required,
        public ?int $threshold,
        public ?int $currentStage,
        public int $totalStages,
        public int $clearedStages,
    ) {}

    /**
     * Fraction of the way to resolution, between 0.0 and 1.0.
     */
    public function ratio(): float
    {
        if ($this->totalStages > 0) {
            return min(1.0, $this->clearedStages / $this->totalStages);
        }

        $target = $this->threshold ?? $this->required;

        if ($target <= 0) {
            return $this->status === ApprovalStatus::Approved ? 1.0 : 0.0;
        }

        return min(1.0, $this->approved / $target);
    }

    public function percentage(): int
    {
        return (int) round($this->ratio() * 100);
    }
}
