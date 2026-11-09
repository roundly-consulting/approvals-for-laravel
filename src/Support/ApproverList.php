<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;

/**
 * Turns the approver models handed to a request (or stage) into the list that is
 * persisted and enforced: each approver once, with the weight it carries on the
 * subject.
 *
 * @internal
 */
final class ApproverList
{
    /**
     * @param  list<Model>  $approvers
     * @return list<NamedApprover>
     */
    public static function name(array $approvers, Model $subject): array
    {
        $weights = app(WeightResolver::class);
        $named = [];

        foreach ($approvers as $approver) {
            $key = $approver->getKey();

            if (! is_int($key) && ! is_string($key)) {
                throw InvalidApprovalRequestException::unsavedApprover($approver);
            }

            if (NamedApprover::listIncludes($named, $approver)) {
                continue;
            }

            $named[] = new NamedApprover($approver->getMorphClass(), $key, $weights->resolve($approver, $subject));
        }

        return $named;
    }

    /**
     * The identity of an actor handed in for exclusion (or by an exclusion resolver), or
     * null when it is not a saved model.
     */
    public static function identify(mixed $actor): ?NamedApprover
    {
        if (! $actor instanceof Model) {
            return null;
        }

        $key = $actor->getKey();

        return is_int($key) || is_string($key) ? new NamedApprover($actor->getMorphClass(), $key) : null;
    }

    /**
     * The actors a round's opener excludes from deciding it, each once.
     *
     * @param  list<Model>  $actors
     * @return list<NamedApprover>
     *
     * @throws InvalidApprovalRequestException when an actor is not saved
     */
    public static function excluded(array $actors): array
    {
        $excluded = [];

        foreach ($actors as $actor) {
            $identified = self::identify($actor) ?? throw InvalidApprovalRequestException::unsavedExcludedActor($actor);

            if (! NamedApprover::listIncludes($excluded, $actor)) {
                $excluded[] = $identified;
            }
        }

        return $excluded;
    }

    /**
     * The JSON column value for an exclusion list: NULL when nobody is excluded.
     *
     * @param  list<NamedApprover>  $excluded
     * @return list<array{type: string, id: int|string}>|null
     */
    public static function exclusionPayload(array $excluded): ?array
    {
        return $excluded === []
            ? null
            : array_map(static fn (NamedApprover $actor): array => ['type' => $actor->type, 'id' => $actor->id], $excluded);
    }

    /**
     * Refuse naming an approver who is excluded from deciding: it could never decide, so
     * the round (or stage) would wait on it for nothing.
     *
     * @param  list<NamedApprover>  $named
     * @param  list<NamedApprover>  $excluded
     *
     * @throws InvalidApprovalRequestException
     */
    public static function ensureNoneExcluded(array $named, array $excluded): void
    {
        foreach ($named as $approver) {
            foreach ($excluded as $actor) {
                if ($approver->is($actor)) {
                    throw InvalidApprovalRequestException::excludedApprover($approver);
                }
            }
        }
    }

    /**
     * The JSON column value for a named list: NULL when nobody was named, which leaves
     * the request (or stage) open to any approver.
     *
     * @param  list<NamedApprover>  $named
     * @return list<array{type: string, id: int|string, weight: int}>|null
     */
    public static function payload(array $named): ?array
    {
        return $named === []
            ? null
            : array_map(static fn (NamedApprover $approver): array => $approver->toPayload(), $named);
    }

    /**
     * The headcount a request (or stage) needs: the explicit figure, else one per named
     * approver. Named approvers are the only ones who can decide, so needing more of
     * them than were named could never resolve.
     *
     * @param  list<NamedApprover>  $named
     */
    public static function required(array $named, ?int $required): int
    {
        $required ??= count($named);

        if ($named !== [] && $required > count($named)) {
            throw InvalidApprovalRequestException::moreRequiredThanNamed($required, count($named));
        }

        return $required;
    }

    /**
     * Refuse a request (or stage) its rule could never approve.
     *
     * Unanimous approves once `required` approvals are in, and Quorum / Weighted once
     * the approvals reach `quorum ?? required`; a figure below 1 is never reached, so
     * such a request could only ever be rejected (an unnamed request under the default
     * rule, for one). Any approves on the first approval and is always reachable.
     *
     * A quorum/weighted threshold is refused too when it could never be met: below 1,
     * above the total weight the named approvers carry, or — for a Quorum request
     * without names, where each approver counts as 1 — above its headcount. (An unnamed
     * Weighted request cannot know what its approvers weigh, so its threshold is taken
     * on trust.)
     *
     * Per-decision `weight()` overrides are not anticipated: give approvers their
     * weight through `ProvidesApprovalWeight` when a request depends on it.
     *
     * @param  list<NamedApprover>  $named
     */
    public static function ensureReachable(ApprovalRule $rule, ?int $quorum, int $required, array $named): void
    {
        if ($rule->isWeighted() && $quorum !== null && $quorum < 1) {
            throw InvalidApprovalRequestException::thresholdBelowOne($quorum);
        }

        $needed = match ($rule) {
            ApprovalRule::Any => 1,
            ApprovalRule::Unanimous => $required,
            ApprovalRule::Quorum, ApprovalRule::Weighted => $quorum ?? $required,
        };

        if ($needed < 1) {
            throw InvalidApprovalRequestException::needsNoApprovals($rule);
        }

        if (! $rule->isWeighted()) {
            return;
        }

        $threshold = $quorum ?? $required;

        $reachable = match (true) {
            $named !== [] => array_sum(array_map(static fn (NamedApprover $approver): int => $approver->weight, $named)),
            $rule === ApprovalRule::Quorum && $required > 0 => $required,
            default => null,
        };

        if ($reachable !== null && $threshold > $reachable) {
            throw InvalidApprovalRequestException::unreachableThreshold($threshold, $reachable);
        }
    }
}
