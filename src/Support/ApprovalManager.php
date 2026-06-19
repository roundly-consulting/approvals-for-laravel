<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\ExpireApprovalsAction;
use RoundlyConsulting\Approvals\Builders\PendingApproval;

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
     * Lapse expired pending approvals.
     *
     * @return int the number of approvals expired
     */
    public function expire(?CarbonInterface $now = null): int
    {
        return app(ExpireApprovalsAction::class)->execute($now);
    }
}
