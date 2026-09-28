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
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

final class ExpireApprovalsAction
{
    /**
     * Lapse every decision (pending or approved) and every pending request whose expiry
     * is at or before the given moment (now when omitted).
     *
     * @return int the number of decisions and requests expired
     */
    public function execute(?CarbonInterface $now = null): int
    {
        $now ??= CarbonImmutable::now();

        return $this->expireDecisions($now) + $this->expireRequests($now);
    }

    private function expireDecisions(CarbonInterface $now): int
    {
        $model = ApprovalModelResolver::class();

        $approvals = $model::query()
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved])
            ->expiringBefore($now)
            ->get();

        foreach ($approvals as $approval) {
            $this->lapse($approval);
        }

        return $approvals->count();
    }

    private function expireRequests(CarbonInterface $now): int
    {
        $model = ApprovalRequestModelResolver::class();

        $count = 0;

        $overdue = $model::query()
            ->where('status', ApprovalStatus::Pending)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($overdue as $request) {
            if ($request->lapseIfOverdue($now)) {
                $count++;
            }
        }

        return $count;
    }

    private function lapse(Approval $approval): void
    {
        $from = $approval->status;

        $approval->markExpired();

        ApprovalExpired::dispatch($approval);

        ApprovalStatusChanged::dispatch($approval, $from, ApprovalStatus::Expired, $this->actorOf($approval));
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
