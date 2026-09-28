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

    private static function keyOf(Model $model): string
    {
        $key = $model->getKey();

        return is_scalar($key) ? (string) $key : '?';
    }
}
