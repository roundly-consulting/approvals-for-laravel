<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

final class InvalidApprovalRequestException extends ApprovalsException
{
    /**
     * A decision was pinned to a request that belongs to a different subject.
     */
    public static function foreignSubject(ApprovalRequest $request, Model $approvable): self
    {
        return new self(
            'Approval request ['.$request->getKey().'] does not belong to the approvable ['
            .$approvable->getMorphClass().'#'.self::keyOf($approvable).'].'
        );
    }

    /**
     * A request builder was given both flat approvers and stages.
     */
    public static function mixedApprovers(): self
    {
        return new self('An approval request takes either flat approvers (from()) or stages (stages()), not both.');
    }

    /**
     * Settings a workflow preset defines were made on the request builder before
     * `workflow()`. The preset would have dropped them, so they are refused instead.
     *
     * @param  list<string>  $settings  the builder methods called, e.g. `from()`, `quorum()`
     */
    public static function definedByWorkflow(string $workflow, array $settings): self
    {
        $message = "The approval workflow preset [{$workflow}] defines the rule, quorum, stages and stage rejection itself "
            .'and takes its approvers in open(), so ['.implode(', ', $settings).'] cannot be set before workflow().';

        if (in_array('from()', $settings, true)) {
            $message .= ' Pass the approvers to open($approvers) instead of from().';
        }

        return new self($message);
    }

    /**
     * An approver was named before it was saved, so it has no key to be matched by.
     */
    public static function unsavedApprover(Model $approver): self
    {
        return new self('Approver ['.$approver->getMorphClass().'] must be saved before it can be named on an approval request.');
    }

    /**
     * A request (or stage) needs more approvals than it named approvers — only named
     * approvers can decide, so it could never resolve.
     */
    public static function moreRequiredThanNamed(int $required, int $named): self
    {
        return new self("An approval request cannot require {$required} approvals from {$named} named approver(s).");
    }

    /**
     * A request (or stage) that needs no approvals: its rule only approves once a figure
     * above zero is reached, so it could never be approved.
     */
    public static function needsNoApprovals(ApprovalRule $rule): self
    {
        return new self(
            "An approval request under the [{$rule->value}] rule needs at least one approval to resolve: "
            .'name its approvers, set how many approvals it needs or a quorum, or use the [any] rule.'
        );
    }

    public static function thresholdBelowOne(int $quorum): self
    {
        return new self("An approval request's quorum must be at least 1, {$quorum} given.");
    }

    /**
     * The approvers in play could never reach the quorum/weighted threshold, even if
     * every one of them approved.
     */
    public static function unreachableThreshold(int $threshold, int $reachable): self
    {
        return new self("An approval request's threshold of {$threshold} can never be met: its approvers carry {$reachable} at most.");
    }

    private static function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '?';
    }
}
