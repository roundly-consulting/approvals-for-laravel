<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Approvals\Database\Factories\ApprovalFactory;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidStatusTransitionException;
use RoundlyConsulting\Approvals\Support\ApprovalRequestStageModelResolver;
use RoundlyConsulting\Approvals\Support\DecisionScope;

/**
 * @property int $id
 * @property int $actor_id
 * @property string $actor_type
 * @property int $approvable_id
 * @property string $approvable_type
 * @property ApprovalStatus $status
 * @property string|null $reason
 * @property int|null $approval_request_id
 * @property string|null $approval_request_type
 * @property string $decision_scope
 * @property bool|null $live
 * @property int|null $approval_request_stage_id
 * @property int|null $decided_by_id
 * @property string|null $decided_by_type
 * @property int $weight
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Approval extends Model
{
    /** @use HasFactory<ApprovalFactory> */
    use HasFactory;

    use SoftDeletes {
        runSoftDelete as protected softDeleteRow;
    }

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'weight' => 'integer',
            'live' => 'boolean',
            'decided_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The model that physically made the decision (a delegate), when it differs from the actor.
     *
     * @return MorphTo<Model, $this>
     */
    public function decidedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<ApprovalRequestStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestStageModelResolver::class(), 'approval_request_stage_id');
    }

    /**
     * Whether this decision was made by a delegate on behalf of the actor.
     */
    public function wasDelegated(): bool
    {
        return $this->decided_by_id !== null && $this->decided_by_type !== null;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvalRequest(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Pending);
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Approved);
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopeRejected(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Rejected);
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopeExpired(Builder $query): void
    {
        $query->where('status', ApprovalStatus::Expired);
    }

    /**
     * Approvals that are still in play (pending or approved, not withdrawn/rejected/expired).
     *
     * @param  Builder<Approval>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Approved]);
    }

    /**
     * The actor's live decision in each slot: pending, approved or rejected, and not yet
     * withdrawn, superseded, expired or soft-deleted.
     *
     * @param  Builder<Approval>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('live', true);
    }

    /**
     * Decisions still in force at the moment (now when omitted): no expiry, or one that
     * has not passed yet. A decision past its expiry stops counting at once, before the
     * `approvals:expire` sweep gets to it.
     *
     * @param  Builder<Approval>  $query
     */
    public function scopeInForce(Builder $query, ?CarbonInterface $moment = null): void
    {
        $moment ??= CarbonImmutable::now();

        $query->where(function (Builder $q) use ($moment): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', $moment);
        });
    }

    /**
     * Whether the decision is still in force at the moment (now when omitted).
     */
    public function isInForce(?CarbonInterface $moment = null): bool
    {
        return $this->expires_at === null || $this->expires_at->greaterThan($moment ?? CarbonImmutable::now());
    }

    /**
     * @param  Builder<Approval>  $query
     */
    public function scopeExpiringBefore(Builder $query, CarbonInterface $moment): void
    {
        $query->whereNotNull('expires_at')->where('expires_at', '<=', $moment);
    }

    /**
     * Approve the decision, valid until `$expiresAt` (for good when null). An answered
     * ask's reply-by deadline is not carried over as the approval's own expiry.
     */
    public function approve(?string $reason = null, ?CarbonInterface $expiresAt = null): static
    {
        $this->transitionTo(ApprovalStatus::Approved);

        $this->reason = $reason ?? $this->reason;
        $this->decided_at = CarbonImmutable::now();
        $this->expires_at = $expiresAt === null ? null : CarbonImmutable::instance($expiresAt->toDateTimeImmutable());

        $this->save();

        return $this;
    }

    public function reject(?string $reason = null): static
    {
        $this->transitionTo(ApprovalStatus::Rejected);

        $this->reason = $reason ?? $this->reason;
        $this->decided_at = CarbonImmutable::now();
        $this->save();

        return $this;
    }

    public function cancel(?string $reason = null): static
    {
        $this->transitionTo(ApprovalStatus::Cancelled);

        $this->reason = $reason ?? $this->reason;
        $this->decided_at = CarbonImmutable::now();
        $this->save();

        return $this;
    }

    public function markExpired(): static
    {
        $this->transitionTo(ApprovalStatus::Expired);

        $this->decided_at = CarbonImmutable::now();
        $this->save();

        return $this;
    }

    private function transitionTo(ApprovalStatus $to): void
    {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw InvalidStatusTransitionException::between($from, $to);
        }

        $this->status = $to;
    }

    /**
     * Keep `decision_scope` and `live` in step with the row on every save — whoever saves
     * it (the actions, a factory, host code) — so the unique live-decision index always
     * sees the truth. Done here rather than in a `saving` listener, because a host test
     * under `Event::fake()` silences model events and would write rows the index cannot
     * guard.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        $this->syncDecisionSlot();

        return parent::save($options);
    }

    /**
     * Derive the slot from the request/stage the decision belongs to, and whether it is
     * still the actor's live decision there (pending, approved or rejected, not trashed).
     *
     * @internal
     */
    public function syncDecisionSlot(): void
    {
        $this->decision_scope = DecisionScope::key($this->approval_request_id, $this->approval_request_stage_id);

        $status = $this->getAttribute('status');

        // An unset status takes the column default (approved) on insert.
        $live = $status instanceof ApprovalStatus ? $status->isLive() : true;

        $this->live = $live && ! $this->trashed() ? true : null;
    }

    /**
     * A soft delete writes only `deleted_at`; free the decision slot as well.
     */
    protected function runSoftDelete(): void
    {
        $this->softDeleteRow();

        $this->live = null;
        $this->newModelQuery()->whereKey($this->getKey())->update(['live' => null]);
        $this->syncOriginalAttribute('live');
    }

    protected static function newFactory(): ApprovalFactory
    {
        return ApprovalFactory::new();
    }
}
