<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Facades;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Builders\DelegationsHandle;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Builders\PendingApprovalRequest;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalProgress;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Testing\ApprovalsFake;
use RoundlyConsulting\Approvals\Testing\RecordedApprovalOperation;

/**
 * @method static PendingApproval for(Model $approvable)
 * @method static PendingApproval as(Model $actor)
 * @method static PendingApprovalRequest request(Model $subject)
 * @method static WorkflowPreset preset(string $name)
 * @method static ApprovalStatus status(Model $subject)
 * @method static ApprovalProgress|null progress(Model $subject)
 * @method static ApprovalRequestStage|null currentStage(Model $subject)
 * @method static DelegationsHandle delegations(Model $delegator)
 * @method static ApprovalDelegation|null delegationFor(Model $delegate, ?CarbonInterface $at = null)
 * @method static int expire(?CarbonInterface $now = null)
 * @method static ApprovalsFake fake()
 * @method static list<RecordedApprovalOperation> recorded(?ApprovalOperation $operation = null)
 * @method static void assertApproved(Model $approvable, ?Model $by = null)
 * @method static void assertNothingApproved()
 * @method static void assertRejected(Model $approvable, ?Model $by = null)
 * @method static void assertNothingRejected()
 * @method static void assertAsked(Model $approvable, ?Model $actor = null)
 * @method static void assertNothingAsked()
 * @method static void assertCancelled(Model $approvable, ?Model $by = null)
 * @method static void assertNothingCancelled()
 * @method static void assertToggled(Model $approvable, ?Model $by = null)
 * @method static void assertNothingToggled()
 * @method static void assertOpened(Model $subject, ?string $workflow = null)
 * @method static void assertNothingOpened()
 * @method static void assertDelegated(Model $delegator, ?Model $to = null)
 * @method static void assertNothingDelegated()
 * @method static void assertRevoked(Model $delegator, ?Model $delegate = null)
 * @method static void assertNothingRevoked()
 * @method static void assertExpired(?int $count = null)
 * @method static void assertNothingExpired()
 *
 * @see ApprovalsManager
 * @see ApprovalsFake
 */
final class Approvals extends Facade
{
    /**
     * Swap in a recording fake. Operations still run; the fake records each one
     * (through the facade, injected managers and the model traits) for assertions.
     */
    public static function fake(): ApprovalsFake
    {
        $fake = app(ApprovalsFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ApprovalsManager::class;
    }
}
