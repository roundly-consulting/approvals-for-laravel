<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use Illuminate\Database\Eloquent\Model;
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
        return new self("An approval request cannot require {$required} approvers when only {$named} are named.");
    }

    private static function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '?';
    }
}
