<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

/**
 * Evaluates an approval rule against tallied decisions, supporting both plain
 * headcount and weighted resolution.
 *
 * For weighted rules (Quorum and Weighted) the threshold and tallies are summed
 * weights; with the default weight of 1 per decision this collapses to a headcount,
 * keeping plain quorum behaviour unchanged.
 */
final class RuleEvaluator
{
    /**
     * @param  int  $approvals  summed weight (or count) of approvals
     * @param  int  $rejections  summed weight (or count) of rejections
     * @param  int  $required  total approver weight (or headcount) in play
     * @param  int|null  $quorum  the quorum / weight threshold for quorum & weighted rules
     */
    public function evaluate(
        ApprovalRule $rule,
        int $approvals,
        int $rejections,
        int $required,
        ?int $quorum = null,
    ): ?ApprovalStatus {
        return match ($rule) {
            ApprovalRule::Any => $this->evaluateAny($approvals, $rejections, $required),
            ApprovalRule::Unanimous => $this->evaluateUnanimous($approvals, $rejections, $required),
            ApprovalRule::Quorum, ApprovalRule::Weighted => $this->evaluateThreshold($approvals, $rejections, $required, $quorum),
        };
    }

    private function evaluateAny(int $approvals, int $rejections, int $required): ?ApprovalStatus
    {
        if ($approvals >= 1) {
            return ApprovalStatus::Approved;
        }

        if ($required > 0 && $rejections >= $required) {
            return ApprovalStatus::Rejected;
        }

        return null;
    }

    private function evaluateUnanimous(int $approvals, int $rejections, int $required): ?ApprovalStatus
    {
        if ($rejections >= 1) {
            return ApprovalStatus::Rejected;
        }

        if ($required > 0 && $approvals >= $required) {
            return ApprovalStatus::Approved;
        }

        return null;
    }

    private function evaluateThreshold(int $approvals, int $rejections, int $required, ?int $quorum): ?ApprovalStatus
    {
        $threshold = $quorum ?? $required;

        if ($threshold > 0 && $approvals >= $threshold) {
            return ApprovalStatus::Approved;
        }

        // Reject as soon as the threshold can no longer be reached.
        if ($required > 0 && ($required - $rejections) < $threshold) {
            return ApprovalStatus::Rejected;
        }

        return null;
    }
}
