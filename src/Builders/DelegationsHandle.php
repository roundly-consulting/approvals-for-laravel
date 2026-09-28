<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\RevokeApprovalDelegationAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Support\DelegationResolver;

/**
 * The delegations one approver hands out: `Approvals::delegations($boss)`. Every
 * operation is scoped to that delegator.
 */
final readonly class DelegationsHandle
{
    public function __construct(
        private ApprovalsManager $manager,
        private Model $delegator,
    ) {}

    /**
     * Start delegating the approver's authority to `$delegate`; finish with grant().
     */
    public function to(Model $delegate): PendingDelegation
    {
        return new PendingDelegation($this->manager, $this->delegator, $delegate);
    }

    /**
     * Revoke the approver's delegations that are in force or scheduled (not yet ended),
     * optionally only those to `$delegate`.
     *
     * @return int the number of delegations revoked
     */
    public function revoke(?Model $delegate = null): int
    {
        return $this->manager->perform(
            ApprovalOperation::Revoke,
            RevokeApprovalDelegationAction::class,
            fn (RevokeApprovalDelegationAction $action): int => $action->execute($this->delegator, $delegate),
            ['delegator' => $this->delegator, 'delegate' => $delegate],
        );
    }

    /**
     * The approver's delegations in force at the moment (now when omitted), newest first.
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function active(?CarbonInterface $at = null): Collection
    {
        return app(DelegationResolver::class)->activeDelegationsFrom($this->delegator, $at);
    }
}
