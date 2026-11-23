<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Events\ApprovalDelegated;
use RoundlyConsulting\Approvals\Exceptions\InvalidDelegationException;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;

final class DelegateApprovalsAction
{
    public function execute(
        Model $delegator,
        Model $delegate,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        ?Model $scope = null,
    ): ApprovalDelegation {
        if ($delegator->is($delegate)) {
            throw InvalidDelegationException::selfDelegation();
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThan($startsAt)) {
            throw InvalidDelegationException::endsBeforeStart($startsAt, $endsAt);
        }

        $scopeKey = $scope?->getKey();

        if ($scope instanceof Model && ! is_int($scopeKey) && ! is_string($scopeKey)) {
            throw InvalidDelegationException::unsavedScope($scope);
        }

        $model = ApprovalDelegationModelResolver::class();

        $delegation = new $model;
        $delegation->delegator_id = $delegator->getKey();
        $delegation->delegator_type = $delegator->getMorphClass();
        $delegation->delegate_id = $delegate->getKey();
        $delegation->delegate_type = $delegate->getMorphClass();
        // Written only when limited, so a host that has not run the 2.4.0 migration keeps
        // delegating until it scopes a delegation.
        if ($scope instanceof Model) {
            $delegation->scope_id = $scopeKey;
            $delegation->scope_type = $scope->getMorphClass();
        }

        $delegation->starts_at = $startsAt === null
            ? null
            : CarbonImmutable::instance($startsAt->toDateTimeImmutable());
        $delegation->ends_at = $endsAt === null
            ? null
            : CarbonImmutable::instance($endsAt->toDateTimeImmutable());
        $delegation->save();

        ApprovalDelegated::dispatch($delegation);

        return $delegation;
    }
}
