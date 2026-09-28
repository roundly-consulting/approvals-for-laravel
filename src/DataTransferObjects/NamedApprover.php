<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\Approval;

/**
 * One approver named on a request or stage: the morph type and key that identify it,
 * and the weight it carried when the request was opened.
 */
final readonly class NamedApprover
{
    public function __construct(
        public string $type,
        public int|string $id,
        public int $weight = 1,
    ) {}

    /**
     * Whether `$model` is this approver. Keys compare as strings, so an integer key read
     * back from JSON still matches a model whose key is a numeric string, and vice versa.
     */
    public function matches(Model $model): bool
    {
        $key = $model->getKey();

        return $model->getMorphClass() === $this->type
            && (is_int($key) || is_string($key))
            && (string) $key === (string) $this->id;
    }

    /**
     * Whether `$decision` was recorded with this approver as its actor.
     */
    public function isActorOf(Approval $decision): bool
    {
        return $decision->actor_type === $this->type
            && (string) $decision->actor_id === (string) $this->id;
    }

    /**
     * The stored form, one entry of a request's or stage's `approvers` JSON column.
     *
     * @return array{type: string, id: int|string, weight: int}
     */
    public function toPayload(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'weight' => $this->weight];
    }

    /**
     * Read an `approvers` column back, skipping any entry that is not a well-formed
     * approver rather than failing the whole request on one bad row.
     *
     * @return list<self>
     */
    public static function listFrom(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $approvers = [];

        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $type = $entry['type'] ?? null;
            $id = $entry['id'] ?? null;
            $weight = $entry['weight'] ?? 1;

            if (! is_string($type) || (! is_int($id) && ! is_string($id))) {
                continue;
            }

            $approvers[] = new self($type, $id, is_int($weight) ? $weight : 1);
        }

        return $approvers;
    }

    /**
     * Whether any of `$approvers` is `$model`.
     *
     * @param  list<self>  $approvers
     */
    public static function listIncludes(array $approvers, Model $model): bool
    {
        foreach ($approvers as $approver) {
            if ($approver->matches($model)) {
                return true;
            }
        }

        return false;
    }
}
