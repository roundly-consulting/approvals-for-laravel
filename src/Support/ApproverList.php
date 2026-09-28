<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
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
}
