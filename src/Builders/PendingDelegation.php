<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\DelegateApprovalsAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;

/**
 * Collects a delegation's window (and, optionally, the one context it counts in) and
 * creates it on grant():
 * `Approvals::delegations($boss)->to($deputy)->within($team)->from($t1)->until($t2)->grant()`.
 *
 * Nothing is written and no event fires until grant(), so the delegation is
 * validated (self-delegation, a window ending before it starts) and announced with
 * its final window.
 */
final class PendingDelegation
{
    private ?CarbonInterface $startsAt = null;

    private ?CarbonInterface $endsAt = null;

    private ?int $duration = null;

    private ?Model $scope = null;

    public function __construct(
        private readonly ApprovalsManager $manager,
        private readonly Model $delegator,
        private readonly Model $delegate,
    ) {}

    public function from(CarbonInterface $when): self
    {
        $this->startsAt = $when;

        return $this;
    }

    public function until(CarbonInterface $when): self
    {
        $this->endsAt = $when;
        $this->duration = null;

        return $this;
    }

    /**
     * Let the delegation last this many seconds from its start (now when no from()).
     */
    public function for(int $seconds): self
    {
        $this->duration = $seconds;
        $this->endsAt = null;

        return $this;
    }

    /**
     * Limit the delegation to one context (a team, a tenant): it then counts only in the
     * rounds `approvals.delegations.scope_resolver` places in that context. Without it
     * the delegation counts everywhere.
     */
    public function within(Model $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function grant(): ApprovalDelegation
    {
        $startsAt = $this->startsAt;
        $endsAt = $this->duration === null
            ? $this->endsAt
            : CarbonImmutable::instance(($startsAt ?? CarbonImmutable::now())->toDateTimeImmutable())->addSeconds($this->duration);

        return $this->manager->perform(
            ApprovalOperation::Delegate,
            DelegateApprovalsAction::class,
            fn (DelegateApprovalsAction $action): ApprovalDelegation => $action->execute($this->delegator, $this->delegate, $startsAt, $endsAt, $this->scope),
            [
                'delegator' => $this->delegator,
                'delegate' => $this->delegate,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'scope' => $this->scope,
            ],
        );
    }
}
