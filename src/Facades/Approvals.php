<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Facades;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Builders\PendingDelegation;
use RoundlyConsulting\Approvals\Support\ApprovalManager;

/**
 * @method static PendingApproval for(Model $approvable)
 * @method static PendingApproval as(Model $actor)
 * @method static PendingDelegation delegate(Model $delegator, Model $delegate)
 * @method static int expire(?CarbonInterface $now = null)
 *
 * @see ApprovalManager
 */
final class Approvals extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApprovalManager::class;
    }
}
