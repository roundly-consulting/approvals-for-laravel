<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproversReplaced;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApproverList;
use RoundlyConsulting\Approvals\Support\ExcludedApprovers;

/**
 * Shared building block of the two replace-approvers actions (a stage's, a flat round's).
 *
 * @internal
 */
trait ReplacesApprovers
{
    /**
     * The subject's latest pending round. A subject whose rounds are all closed is
     * refused as closed, one that never had a round as having none.
     */
    private function openRoundOf(Model $subject): ApprovalRequest
    {
        $model = ApprovalRequestModelResolver::class();

        $round = $model::query()
            ->whereMorphedTo('subject', $subject)
            ->where('status', ApprovalStatus::Pending)
            ->latest('id')
            ->first();

        if ($round instanceof ApprovalRequest) {
            return $round;
        }

        $latest = ApprovalChecker::latestRequest($subject);

        throw $latest instanceof ApprovalRequest
            ? ClosedApprovalRequestException::for($latest)
            : InvalidApprovalRequestException::noRound($subject);
    }

    /**
     * Lock the round's row and read it back: it must still be pending and within its
     * expiry. An overdue round is refused without lapsing it — lapsing would resolve it,
     * which a replacement never does.
     */
    private function lockPendingRound(ApprovalRequest $round, Model $subject): ApprovalRequest
    {
        $locked = $round->newQuery()->whereKey($round->getKey())->lockForUpdate()->first();

        if (! $locked instanceof ApprovalRequest) {
            throw InvalidApprovalRequestException::noRound($subject);
        }

        if ($locked->status !== ApprovalStatus::Pending) {
            throw ClosedApprovalRequestException::for($locked);
        }

        if ($locked->isOverdue()) {
            throw ClosedApprovalRequestException::overdue($locked);
        }

        return $locked;
    }

    /**
     * Refuse a replacement naming an actor excluded from deciding the round.
     *
     * @param  list<NamedApprover>  $named
     */
    private function ensureNoneExcluded(array $named, Model $subject, ApprovalRequest $round): void
    {
        ApproverList::ensureNoneExcluded($named, app(ExcludedApprovers::class)->for($subject, $round));
    }

    /**
     * The approvers holding a decision in force (approved or rejected) among
     * `$decisions`, in the order `$named` lists them, with the weight each was named
     * with. Without names they are the actors who decided, with the weight their
     * decision carries.
     *
     * @param  Builder<Approval>  $decisions
     * @param  list<NamedApprover>  $named
     * @return list<NamedApprover>
     */
    private function decidedApprovers(Builder $decisions, array $named): array
    {
        $decided = $decisions
            ->live()
            ->inForce()
            ->whereIn('status', [ApprovalStatus::Approved, ApprovalStatus::Rejected])
            ->orderBy('id')
            ->get();

        $isDecided = static fn (NamedApprover $approver): bool => $decided->contains(
            static fn (Approval $decision): bool => $approver->isActorOf($decision),
        );

        $kept = array_values(array_filter($named, $isDecided));

        foreach ($decided as $decision) {
            $listed = array_filter($kept, static fn (NamedApprover $approver): bool => $approver->isActorOf($decision));

            if ($listed === []) {
                $kept[] = new NamedApprover($decision->actor_type, $decision->actor_id, $decision->weight);
            }
        }

        return $kept;
    }

    /**
     * Withdraw the outstanding asks among `$decisions` addressed to approvers no longer
     * named: left live they would keep showing as pending, and the asked approver could
     * no longer answer them.
     *
     * @param  Builder<Approval>  $decisions
     * @param  list<NamedApprover>  $named
     * @return list<Approval>
     */
    private function retireAsksOutside(Builder $decisions, array $named): array
    {
        $retired = [];

        $asks = $decisions
            ->live()
            ->where('status', ApprovalStatus::Pending)
            ->orderBy('id')
            ->get();

        foreach ($asks as $ask) {
            $stillNamed = array_filter($named, static fn (NamedApprover $approver): bool => $approver->isActorOf($ask));

            if ($stillNamed === []) {
                $retired[] = $ask->cancel();
            }
        }

        return $retired;
    }

    /**
     * Announce a committed replacement: the retired asks first, then who joined and who
     * left the list.
     *
     * @param  list<Approval>  $retired
     * @param  list<NamedApprover>  $before
     * @param  list<NamedApprover>  $after
     */
    private function announceReplacement(
        array $retired,
        ApprovalRequest $round,
        ?ApprovalRequestStage $stage,
        array $before,
        array $after,
    ): void {
        foreach ($retired as $ask) {
            ApprovalCancelled::dispatch($ask);
            ApprovalStatusChanged::dispatch($ask, ApprovalStatus::Pending, ApprovalStatus::Cancelled);
        }

        ApprovalApproversReplaced::dispatch(
            $round,
            $stage,
            self::missingFrom($after, $before),
            self::missingFrom($before, $after),
        );
    }

    /**
     * The approvers in `$list` that `$other` does not name.
     *
     * @param  list<NamedApprover>  $list
     * @param  list<NamedApprover>  $other
     * @return list<NamedApprover>
     */
    private static function missingFrom(array $list, array $other): array
    {
        return array_values(array_filter(
            $list,
            static function (NamedApprover $approver) use ($other): bool {
                foreach ($other as $candidate) {
                    if ($candidate->is($approver)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }
}
