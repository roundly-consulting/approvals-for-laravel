<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundlyConsulting\Approvals\Events\ApprovalExpired;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

final class ExpireApprovalsAction
{
    /**
     * Lapse every pending approval whose expiry is at or before the given moment.
     *
     * @return int the number of approvals expired
     */
    public function execute(?CarbonInterface $now = null): int
    {
        $now ??= CarbonImmutable::now();

        $model = ApprovalModelResolver::class();

        $approvals = $model::query()
            ->pending()
            ->expiringBefore($now)
            ->get();

        $count = 0;

        foreach ($approvals as $approval) {
            $approval->markExpired();

            ApprovalExpired::dispatch($approval);

            $count++;
        }

        return $count;
    }
}
