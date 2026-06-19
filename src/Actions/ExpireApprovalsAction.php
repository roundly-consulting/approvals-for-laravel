<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalExpired;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Models\Approval;
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
            $from = $approval->status;

            $approval->markExpired();

            ApprovalExpired::dispatch($approval);

            ApprovalStatusChanged::dispatch($approval, $from, ApprovalStatus::Expired, $this->actorOf($approval));

            $count++;
        }

        return $count;
    }

    /**
     * Resolve the recorded actor only when its morph alias maps to a known class, so an
     * unmapped alias never breaks the lapse.
     */
    private function actorOf(Approval $approval): ?Model
    {
        $type = $approval->actor_type;

        if (Relation::getMorphedModel($type) === null && ! class_exists($type)) {
            return null;
        }

        $actor = $approval->actor;

        return $actor instanceof Model ? $actor : null;
    }
}
