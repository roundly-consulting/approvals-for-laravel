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
    ): ApprovalDelegation {
        if ($delegator->is($delegate)) {
            throw InvalidDelegationException::selfDelegation();
        }

        if ($startsAt !== null && $endsAt !== null && $endsAt->lessThan($startsAt)) {
            throw InvalidDelegationException::endsBeforeStart($startsAt, $endsAt);
        }

        $model = ApprovalDelegationModelResolver::class();

        $delegation = new $model;
        $delegation->delegator_id = $delegator->getKey();
        $delegation->delegator_type = $delegator->getMorphClass();
        $delegation->delegate_id = $delegate->getKey();
        $delegation->delegate_type = $delegate->getMorphClass();
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
