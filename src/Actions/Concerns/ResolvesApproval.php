<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTarget;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\DataTransferObjects\RecordedDecision;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalExpired;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\DelegationResolver;
use RoundlyConsulting\Approvals\Support\LiveDecisions;
use RoundlyConsulting\Approvals\Support\WeightResolver;

/**
 * Shared building block of the decision actions.
 *
 * @internal
 */
trait ResolvesApproval
{
    /**
     * Work out where a decision lands: the request it counts towards (the one given, or
     * the approvable's latest open request), the stage open on it, and whose authority
     * the actor decides with.
     *
     * When the request (or its open stage) names its approvers, only they may decide:
     * a named approver decides as itself — even when it also stands in for someone —
     * and anyone else only as the delegate of a named approver. Anyone else is refused
     * with an UnauthorizedApprovalException. A request opened without names keeps the
     * open semantics: any actor counts, and an active delegation makes the actor decide
     * for its delegator.
     *
     * `$actsForOthers` is false for an ask(): asking someone for a decision is addressed
     * to that model itself, never to whoever it may be standing in for.
     */
    protected function decisionTarget(
        Model $actor,
        Model $approvable,
        ?ApprovalRequest $request,
        bool $actsForOthers = true,
    ): DecisionTarget {
        $request = $this->requestFor($approvable, $request);

        $stage = $request instanceof ApprovalRequest && $request->staged
            ? $request->currentStage()
            : null;

        $named = $request instanceof ApprovalRequest ? $request->approversFor($stage) : [];

        if (! $request instanceof ApprovalRequest || $named === []) {
            $decidedBy = null;
            $effectiveActor = $actsForOthers ? $this->effectiveActor($actor, $decidedBy) : $actor;

            return new DecisionTarget($effectiveActor, $decidedBy, $approvable, $request, $stage);
        }

        if (NamedApprover::listIncludes($named, $actor)) {
            return new DecisionTarget($actor, null, $approvable, $request, $stage);
        }

        if ($actsForOthers) {
            foreach (app(DelegationResolver::class)->activeDelegationsTo($actor) as $delegation) {
                $delegator = $delegation->delegator;

                if ($delegator instanceof Model && NamedApprover::listIncludes($named, $delegator)) {
                    return new DecisionTarget($delegator, $actor, $approvable, $request, $stage);
                }
            }
        }

        throw UnauthorizedApprovalException::notAnApprover($actor, $request, $stage);
    }

    /**
     * Lapse the actor's live decision in the target's slot if its expiry has passed, so
     * the slot is free for a new decision (the unique index would refuse one otherwise).
     */
    protected function lapseOverdueDecision(DecisionTarget $target): void
    {
        $live = app(LiveDecisions::class)->in($target);

        if (! $live instanceof Approval || $live->isInForce()) {
            return;
        }

        $from = $live->status;

        $live->markExpired();

        ApprovalExpired::dispatch($live);
        ApprovalStatusChanged::dispatch($live, $from, ApprovalStatus::Expired, $target->actor);
    }

    /**
     * Record `$to` (approved or rejected) as the actor's live decision in the target's
     * slot, and return what changed so the caller can announce it.
     *
     * A pending decision (an ask) is decided in place; an earlier approved/rejected one
     * is superseded — withdrawn, so it stops counting — and a fresh decision is written;
     * repeating the current decision changes nothing.
     *
     * The slot is guarded by a unique index, so when a concurrent request wins the race
     * to write it, the write is retried and then finds that decision instead of
     * counting the actor twice.
     */
    protected function recordDecision(DecisionTarget $target, ApprovalStatus $to, DecisionData $data): RecordedDecision
    {
        $this->lapseOverdueDecision($target);

        return $this->writeInSlot(fn (): RecordedDecision => $this->writeDecision($target, $to, $data));
    }

    /**
     * Run a read-then-write on a decision slot in a transaction, retrying when a
     * concurrent write took the slot first (the unique live-decision index refused
     * ours). The retry re-reads, so it sees the winner's decision.
     *
     * Each attempt is its own transaction — a savepoint inside a host's transaction —
     * so the refused insert never leaves an aborted transaction behind on Postgres.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    protected function writeInSlot(Closure $write): mixed
    {
        $model = ApprovalModelResolver::class();
        $connection = (new $model)->getConnection();

        for ($attempt = 1; ; $attempt++) {
            try {
                return $connection->transaction($write);
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    private function writeDecision(DecisionTarget $target, ApprovalStatus $to, DecisionData $data): RecordedDecision
    {
        $this->ensureRoundStillOpen($target->request);

        $live = app(LiveDecisions::class)->in($target);

        if ($live instanceof Approval && $live->status === $to) {
            return new RecordedDecision($live, $to, changed: false);
        }

        $superseded = null;
        $supersededFrom = null;

        if ($live instanceof Approval && $live->status === ApprovalStatus::Pending) {
            $approval = $live;
        } else {
            if ($live instanceof Approval) {
                $supersededFrom = $live->status;
                $superseded = $live->cancel();
            }

            $approval = $this->newApprovalFor($target);
        }

        $from = $approval->status;

        $this->applyDecisionContext($approval, $target, $data->weight);

        $to === ApprovalStatus::Approved
            ? $approval->approve($data->reason, $data->expiresAt)
            : $approval->reject($data->reason);

        return new RecordedDecision($approval, $from, true, $superseded, $supersededFrom);
    }

    /**
     * Announce the decision an actor changed their mind about: it was withdrawn so the
     * new one is the only one that counts.
     */
    protected function announceSuperseded(RecordedDecision $recorded, Model $actor): void
    {
        if ($recorded->superseded instanceof Approval && $recorded->supersededFrom instanceof ApprovalStatus) {
            ApprovalStatusChanged::dispatch($recorded->superseded, $recorded->supersededFrom, ApprovalStatus::Cancelled, $actor);
        }
    }

    /**
     * Build (without saving) a fresh pending decision in the target's slot.
     */
    protected function newApprovalFor(DecisionTarget $target): Approval
    {
        $model = ApprovalModelResolver::class();

        $approval = new $model;
        $approval->actor_id = $target->actor->getKey();
        $approval->actor_type = $target->actor->getMorphClass();
        $approval->approvable_id = $target->approvable->getKey();
        $approval->approvable_type = $target->approvable->getMorphClass();
        $approval->status = ApprovalStatus::Pending;

        if ($target->request instanceof ApprovalRequest) {
            $approval->approval_request_id = $target->request->getKey();
            $approval->approval_request_type = $target->request->getMorphClass();
        }

        if ($target->stage instanceof ApprovalRequestStage) {
            $approval->approval_request_stage_id = $target->stage->getKey();
        }

        return $approval;
    }

    /**
     * Resolve the request a decision (or a withdrawal) belongs to: the one passed
     * explicitly (which must belong to the approvable and still be open), or the latest
     * open request whose subject is the approvable.
     *
     * A request whose expiry has passed is lapsed on the spot (resolving as expired)
     * rather than waiting for the sweep. A closed request — approved, rejected, cancelled
     * or expired — accepts nothing more: a decision pinned to it is refused, and so is an
     * unpinned one on a subject whose requests are all closed, which used to land as a
     * standalone decision after the round was over. Only a subject that never had a
     * request takes standalone decisions (null is returned).
     *
     * @throws ClosedApprovalRequestException
     */
    protected function requestFor(Model $approvable, ?ApprovalRequest $request): ?ApprovalRequest
    {
        if ($request instanceof ApprovalRequest) {
            $this->ensureRequestBelongsTo($request, $approvable);

            // The stored status, not the caller's copy: it may predate the round closing.
            $request->refresh();
            $request->lapseIfOverdue();

            if ($request->status !== ApprovalStatus::Pending) {
                throw ClosedApprovalRequestException::for($request);
            }

            return $request;
        }

        $model = ApprovalRequestModelResolver::class();

        $pending = $model::query()
            ->whereMorphedTo('subject', $approvable)
            ->where('status', ApprovalStatus::Pending)
            ->latest('id')
            ->get();

        foreach ($pending as $candidate) {
            if (! $candidate->lapseIfOverdue()) {
                return $candidate;
            }
        }

        $latest = ApprovalChecker::latestRequest($approvable);

        if ($latest instanceof ApprovalRequest) {
            throw ClosedApprovalRequestException::for($latest);
        }

        return null;
    }

    /**
     * The answer to a decision that reached a closed round: when the actor already holds
     * that very decision there (as the actor, or as the delegate who made it), repeating
     * it changes nothing and returns it — an idempotent no-op, as in an open round, so a
     * double-submitted approval stays harmless. Any other decision is refused.
     *
     * @throws ClosedApprovalRequestException
     */
    protected function heldInClosedRound(
        ClosedApprovalRequestException $closed,
        Model $actor,
        Model $approvable,
        ApprovalStatus $status,
    ): Approval {
        $model = ApprovalModelResolver::class();

        foreach (['actor', 'decidedBy'] as $relation) {
            $held = $model::query()
                ->where('approval_request_id', $closed->request->getKey())
                ->whereMorphedTo('approvable', $approvable)
                ->whereMorphedTo($relation, $actor)
                ->where('status', $status)
                ->live()
                ->inForce()
                ->latest('id')
                ->first();

            if ($held instanceof Approval) {
                return $held;
            }
        }

        throw $closed;
    }

    /**
     * Run inside the write's transaction: lock the request row and refuse the write when
     * the round closed after the request was resolved (a concurrent decision resolved it,
     * or the host closed it). The lock holds a resolution back until the write commits,
     * so a decision or a withdrawal is never recorded on a closed request.
     *
     * @throws ClosedApprovalRequestException
     */
    protected function ensureRoundStillOpen(?ApprovalRequest $request): void
    {
        if (! $request instanceof ApprovalRequest) {
            return;
        }

        $stored = $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->first();

        if ($stored instanceof ApprovalRequest && $stored->status !== ApprovalStatus::Pending) {
            throw ClosedApprovalRequestException::for($stored);
        }
    }

    /**
     * Refuse a request whose subject is not the approvable: a decision on one model
     * must never count towards another model's request.
     */
    protected function ensureRequestBelongsTo(ApprovalRequest $request, Model $approvable): void
    {
        $key = $approvable->getKey();

        $belongs = $request->subject_type === $approvable->getMorphClass()
            && $request->subject_id !== null
            && is_scalar($key)
            && (string) $request->subject_id === (string) $key;

        if (! $belongs) {
            throw InvalidApprovalRequestException::foreignSubject($request, $approvable);
        }
    }

    /**
     * Resolve the effective approver for a decision, honouring active delegations.
     *
     * Returns the model whose authority the decision counts as. When a delegation is
     * in force, the original delegate is returned via $decidedBy so the action can
     * record who physically decided.
     */
    protected function effectiveActor(Model $actor, ?Model &$decidedBy): Model
    {
        $delegation = app(DelegationResolver::class)->activeDelegationFor($actor);

        if ($delegation === null) {
            return $actor;
        }

        $delegator = $delegation->delegator;

        if (! $delegator instanceof Model) {
            return $actor;
        }

        // The acting model decided on behalf of the delegator.
        $decidedBy = $actor;

        return $delegator;
    }

    /**
     * Stamp delegation and weight onto an approval before it is decided.
     *
     * The weight is resolved from the in-memory effective actor (not a reloaded
     * relation) so a ProvidesApprovalWeight value set at runtime is respected.
     */
    protected function applyDecisionContext(Approval $approval, DecisionTarget $target, ?int $weightOverride = null): void
    {
        if ($target->decidedBy instanceof Model) {
            $approval->decided_by_id = $target->decidedBy->getKey();
            $approval->decided_by_type = $target->decidedBy->getMorphClass();
        }

        $approval->weight = app(WeightResolver::class)->resolve($target->actor, $target->approvable, $weightOverride);
    }
}
