<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;

/**
 * One operation recorded by {@see ApprovalsFake}: what ran, with which models, and
 * what it returned.
 *
 * Context keys: `actor`, `approvable`, `request`, `reason`, `weight`, `expires_at`
 * (decisions: the builder's settings as given, null when unset); `subject`,
 * `staged`, `workflow` (open); `subject`, `request`, `outcome` (close); `delegator`,
 * `delegate`, `starts_at`, `ends_at` (delegate / revoke); `now`, `subjectType` (expire).
 */
final readonly class RecordedApprovalOperation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public ApprovalOperation $operation,
        public array $context,
        public mixed $result,
    ) {}

    /**
     * The model recorded under the context key, if any.
     */
    public function model(string $key): ?Model
    {
        $value = $this->context[$key] ?? null;

        return $value instanceof Model ? $value : null;
    }

    /**
     * Whether the context key holds the same model as `$model`.
     */
    public function involves(string $key, Model $model): bool
    {
        return $this->model($key)?->is($model) === true;
    }
}
