<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTally;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

/**
 * Evaluates an approval rule against the decisions tallied so far.
 *
 * - Any: approved on the first approval; rejected once every approver has decided
 *   without one.
 * - Unanimous: rejected on the first rejection; approved once the required headcount
 *   has approved.
 * - Quorum and Weighted: approved once the summed approval weight reaches the threshold
 *   (with the default weight of 1 per decision this is a headcount); rejected once the
 *   threshold is out of reach — the approvals in plus the weight the undecided
 *   approvers could still add fall short of it. When that weight cannot be known (an
 *   unnamed Weighted request), only once every approver has decided.
 */
final class RuleEvaluator
{
    public function evaluate(ApprovalRule $rule, DecisionTally $tally): ?ApprovalStatus
    {
        return match ($rule) {
            ApprovalRule::Any => $this->evaluateAny($tally),
            ApprovalRule::Unanimous => $this->evaluateUnanimous($tally),
            ApprovalRule::Quorum, ApprovalRule::Weighted => $this->evaluateThreshold($tally),
        };
    }

    private function evaluateAny(DecisionTally $tally): ?ApprovalStatus
    {
        if ($tally->approvedCount >= 1) {
            return ApprovalStatus::Approved;
        }

        if ($tally->required > 0 && $tally->outstandingCount === 0) {
            return ApprovalStatus::Rejected;
        }

        return null;
    }

    private function evaluateUnanimous(DecisionTally $tally): ?ApprovalStatus
    {
        if ($tally->rejectedCount >= 1) {
            return ApprovalStatus::Rejected;
        }

        if ($tally->required > 0 && $tally->approvedCount >= $tally->required) {
            return ApprovalStatus::Approved;
        }

        return null;
    }

    private function evaluateThreshold(DecisionTally $tally): ?ApprovalStatus
    {
        $threshold = $tally->threshold();

        if ($threshold > 0 && $tally->approvedWeight >= $threshold) {
            return ApprovalStatus::Approved;
        }

        if ($tally->required <= 0) {
            return null;
        }

        $unreachable = $tally->outstandingWeight === null
            ? $tally->outstandingCount === 0
            : $tally->approvedWeight + $tally->outstandingWeight < $threshold;

        return $unreachable ? ApprovalStatus::Rejected : null;
    }
}
