<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Resolves the effective approver behind a decision, honouring active delegations.
 *
 * Given a `$scope`, only the delegations valid within it count: those with no scope and
 * those limited to exactly that scope. Without one, every delegation counts.
 */
final class DelegationResolver
{
    /**
     * Find an active delegation under which the given model is acting as a delegate.
     *
     * The most recently created active delegation wins when several exist.
     */
    public function activeDelegationFor(Model $delegate, ?CarbonInterface $moment = null, ?Model $scope = null): ?ApprovalDelegation
    {
        return $this->firstTo($delegate, $moment, self::context($scope));
    }

    /**
     * Every delegation under which the given model acts as a delegate at the moment,
     * newest first.
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function activeDelegationsTo(Model $delegate, ?CarbonInterface $moment = null, ?Model $scope = null): Collection
    {
        return $this->query('delegate', $delegate, $moment, self::context($scope))->get();
    }

    /**
     * The delegations the given delegator has in force at the moment, newest first.
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function activeDelegationsFrom(Model $delegator, ?CarbonInterface $moment = null, ?Model $scope = null): Collection
    {
        return $this->query('delegator', $delegator, $moment, self::context($scope))->get();
    }

    /**
     * The newest delegation `$delegate` acts under that counts in `$round`.
     *
     * @internal
     */
    public function activeDelegationForRound(Model $delegate, ?ApprovalRequest $round): ?ApprovalDelegation
    {
        return $this->firstTo($delegate, null, DelegationContext::forRound($round));
    }

    /**
     * The delegations `$delegate` acts under that count in `$round`, newest first.
     *
     * @internal
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function activeDelegationsToInRound(Model $delegate, ?ApprovalRequest $round): Collection
    {
        return $this->query('delegate', $delegate, null, DelegationContext::forRound($round))->get();
    }

    /**
     * The delegations `$delegator` has in force that count in `$round`, newest first.
     *
     * @internal
     *
     * @return Collection<int, ApprovalDelegation>
     */
    public function activeDelegationsFromInRound(Model $delegator, ?ApprovalRequest $round): Collection
    {
        return $this->query('delegator', $delegator, null, DelegationContext::forRound($round))->get();
    }

    private static function context(?Model $scope): DelegationContext
    {
        return $scope instanceof Model ? DelegationContext::within($scope) : DelegationContext::anywhere();
    }

    private function firstTo(Model $delegate, ?CarbonInterface $moment, DelegationContext $context): ?ApprovalDelegation
    {
        $delegation = $this->query('delegate', $delegate, $moment, $context)->first();

        return $delegation instanceof ApprovalDelegation ? $delegation : null;
    }

    /**
     * @return Builder<ApprovalDelegation>
     */
    private function query(string $side, Model $model, ?CarbonInterface $moment, DelegationContext $context): Builder
    {
        $class = ApprovalDelegationModelResolver::class();

        $query = $class::query()
            ->whereMorphedTo($side, $model)
            ->active($moment);

        $context->apply($query);

        return $query->latest('id');
    }
}
