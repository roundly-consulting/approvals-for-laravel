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

/**
 * @property int $id
 * @property int $delegator_id
 * @property string $delegator_type
 * @property int $delegate_id
 * @property string $delegate_type
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

    public function revoke(?CarbonInterface $at = null): static
    {
        $this->revoked_at = $at === null
            ? CarbonImmutable::now()
            : CarbonImmutable::instance($at->toDateTimeImmutable());
        $this->save();

        return $this;
    }

    protected static function newFactory(): ApprovalDelegationFactory
    {
        return ApprovalDelegationFactory::new();
    }
}
