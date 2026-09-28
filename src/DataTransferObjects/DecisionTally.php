<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use RoundlyConsulting\Approvals\Support\RuleEvaluator;

/**
 * The decisions counted towards a request (or stage) so far, and what is still
 * outstanding — the input to {@see RuleEvaluator}.
 *
 * Counts are headcounts (one live decision per approver); weights are the summed
 * `weight` of those decisions.
 */
final readonly class DecisionTally
{
    /**
     * @param  int  $required  the approvals the request needs (its headcount)
     * @param  int|null  $quorum  the threshold of the quorum and weighted rules; the headcount when null
     * @param  int  $outstandingCount  approvers who have not decided yet
     * @param  int|null  $outstandingWeight  the weight they could still add; null when it cannot be known
     */
    public function __construct(
        public int $approvedCount,
        public int $rejectedCount,
        public int $approvedWeight,
        public int $rejectedWeight,
        public int $required,
        public ?int $quorum,
        public int $outstandingCount,
        public ?int $outstandingWeight,
    ) {}

    /**
     * The approval weight the quorum and weighted rules resolve on.
     */
    public function threshold(): int
    {
        return $this->quorum ?? $this->required;
    }
}
