<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

final class UnauthorizedApprovalException extends ApprovalsException
{
    public static function forActor(Model $actor): self
    {
        return new self(
            'The actor ['.$actor::class.'] is not authorized to decide on this approval.'
        );
    }

    /**
     * The actor is not one of the approvers the request (or its open stage) names, and
     * holds no delegation from one who is.
     */
    public static function notAnApprover(Model $actor, ApprovalRequest $request, ?ApprovalRequestStage $stage = null): self
    {
        $key = $actor->getKey();
        $who = $actor->getMorphClass().'#'.(is_int($key) || is_string($key) ? $key : '?');

        $where = $stage instanceof ApprovalRequestStage
            ? 'stage '.$stage->position.($stage->name !== null ? ' ['.$stage->name.']' : '').' of approval request ['.$request->getKey().']'
            : 'approval request ['.$request->getKey().']';

        return new self("The actor [{$who}] is not a named approver of {$where}.");
    }
}
