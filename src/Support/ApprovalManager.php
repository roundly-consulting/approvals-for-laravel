<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\ExpireApprovalsAction;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Builders\PendingDelegation;

final class ApprovalManager
{
    /**
     * Begin a fluent approval targeting the given approvable.
     */
    public function for(Model $approvable): PendingApproval
    {
        return (new PendingApproval)->for($approvable);
    }

    /**
     * Begin a fluent approval acting as the given actor.
     */
    public function as(Model $actor): PendingApproval
    {
        return (new PendingApproval)->as($actor);
    }

    /**
     * Begin delegating approval authority from one approver to another.
     */
    public function delegate(Model $delegator, Model $delegate): PendingDelegation
    {
        return new PendingDelegation($delegator, $delegate);
    }

    /**
     * Lapse expired pending approvals.
     *
     * @return int the number of approvals expired
     */
    public function expire(?CarbonInterface $now = null): int
    {
        return app(ExpireApprovalsAction::class)->execute($now);
    }
}
