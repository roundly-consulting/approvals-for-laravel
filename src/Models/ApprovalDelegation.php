<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Approvals\Database\Factories\ApprovalDelegationFactory;
use RoundlyConsulting\Approvals\Exceptions\InvalidDelegationException;

/**
 * @property int $id
 * @property int $delegator_id
 * @property string $delegator_type
 * @property int $delegate_id
 * @property string $delegate_type
 * @property int|string|null $scope_id
 * @property string|null $scope_type
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class ApprovalDelegation extends Model
{
    /** @use HasFactory<ApprovalDelegationFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function delegator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function delegate(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The context the delegation is limited to (a team, a tenant); null when it counts
     * everywhere.
     *
     * @return MorphTo<Model, $this>
     */
    public function scope(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether the delegation is limited to one context.
     */
    public function isScoped(): bool
    {
        return $this->getAttribute('scope_type') !== null;
    }

    /**
     * Whether the delegation counts within `$scope`: it has no scope, or exactly that one.
     * Within no scope (null) only an unscoped delegation counts.
     */
    public function countsWithin(?Model $scope): bool
    {
        if (! $this->isScoped()) {
            return true;
        }

        return $scope instanceof Model
            && $this->getAttribute('scope_type') === $scope->getMorphClass()
            && (string) $this->getAttribute('scope_id') === (string) $scope->getKey();
    }

    /**
     * Whether the delegation is in force at the given moment (started, not ended, not revoked).
     */
    public function isActiveAt(?CarbonInterface $moment = null): bool
    {
        $moment = $moment === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($moment->toDateTimeImmutable());

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->starts_at !== null && $moment->lessThan($this->starts_at)) {
            return false;
        }

        if ($this->ends_at !== null && $moment->greaterThan($this->ends_at)) {
            return false;
        }

        return true;
    }

    /**
     * Delegations that are currently in force (started, not yet ended, not revoked).
     *
     * @param  Builder<ApprovalDelegation>  $query
     */
    public function scopeActive(Builder $query, ?CarbonInterface $moment = null): void
    {
        $moment ??= CarbonImmutable::now();

        $query->whereNull('revoked_at')
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $moment);
            })
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $moment);
            });
    }

    /**
     * Delegations that count within `$scope`: those with no scope, plus those limited to
     * exactly `$scope`. Within no scope (null) only the unscoped ones count.
     *
     * @param  Builder<ApprovalDelegation>  $query
     */
    public function scopeWithin(Builder $query, ?Model $scope): void
    {
        $query->where(static function (Builder $q) use ($scope): void {
            $q->whereNull('scope_type');

            if ($scope instanceof Model) {
                $q->orWhere(static function (Builder $exact) use ($scope): void {
                    $exact->where('scope_type', $scope->getMorphClass())
                        ->where('scope_id', $scope->getKey());
                });
            }
        });
    }

    /**
     * Delegations that are in force now or scheduled to start later: not revoked and not
     * yet ended. This is what "revoke" has to reach — a delegation scheduled for next week
     * that survives a "revoke all" would silently hand the authority over anyway.
     *
     * @param  Builder<ApprovalDelegation>  $query
     */
    public function scopeInForceOrScheduled(Builder $query, ?CarbonInterface $moment = null): void
    {
        $moment ??= CarbonImmutable::now();

        $query->whereNull('revoked_at')
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $moment);
            });
    }

    /**
     * Revoke the delegation. Revocation is a kill switch: it ends the delegation at once,
     * for every moment, and `$at` only records when it happened (now when omitted). A
     * moment still in the future is refused — a revocation cannot be scheduled; end a
     * delegation later with its `ends_at` instead.
     *
     * @throws InvalidDelegationException
     */
    public function revoke(?CarbonInterface $at = null): static
    {
        $now = CarbonImmutable::now();
        $at = $at === null ? $now : CarbonImmutable::instance($at->toDateTimeImmutable());

        if ($at->greaterThan($now)) {
            throw InvalidDelegationException::futureRevocation($at);
        }

        $this->revoked_at = $at;
        $this->save();

        return $this;
    }

    protected static function newFactory(): ApprovalDelegationFactory
    {
        return ApprovalDelegationFactory::new();
    }
}
