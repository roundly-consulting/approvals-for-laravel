<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Approvals\Database\Factories\ApprovalConfirmationFactory;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationExpired;

/**
 * A single-use confirmation: one named person confirms one exact action, identified by
 * its payload hash, once, before it expires.
 *
 * A pending confirmation past its expiry already reads as expired (isExpired(),
 * effectiveStatus(), and the pending scopes leave it out); the sweep
 * (`Approvals::expireConfirmations()`, `approvals:expire-confirmations`) or the next
 * answer finalises it and fires ApprovalConfirmationExpired.
 *
 * @property int $id
 * @property int|string $subject_id
 * @property string $subject_type
 * @property int|string $person_id
 * @property string $person_type
 * @property string $payload_hash
 * @property ConfirmationStatus $status
 * @property string|null $reason
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ApprovalConfirmation extends Model
{
    /** @use HasFactory<ApprovalConfirmationFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * The payload hash binds the confirmation to one action. It is not a secret on its
     * own, but nothing outside the package needs it rendered.
     *
     * @var list<string>
     */
    protected $hidden = ['payload_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConfirmationStatus::class,
            'expires_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    /**
     * The model the confirmation is about (the tool call, the record the action touches).
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The one person who may confirm or decline it.
     *
     * @return MorphTo<Model, $this>
     */
    public function person(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Confirmations still open to an answer: pending and not past their expiry.
     *
     * @param  Builder<ApprovalConfirmation>  $query
     */
    public function scopePending(Builder $query, ?CarbonInterface $moment = null): void
    {
        $moment ??= CarbonImmutable::now();

        $query->where('status', ConfirmationStatus::Pending->value)
            ->where(function (Builder $q) use ($moment): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $moment);
            });
    }

    /**
     * Confirmations asked of the person that are still open to an answer.
     *
     * @param  Builder<ApprovalConfirmation>  $query
     */
    public function scopePendingFor(Builder $query, Model $person, ?CarbonInterface $moment = null): void
    {
        $query->whereMorphedTo('person', $person);

        $this->scopePending($query, $moment);
    }

    /**
     * Every confirmation about the subject, whatever its status.
     *
     * @param  Builder<ApprovalConfirmation>  $query
     */
    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->whereMorphedTo('subject', $subject);
    }

    /**
     * Pending confirmations whose expiry is at or before the moment: what the sweep lapses.
     *
     * @param  Builder<ApprovalConfirmation>  $query
     */
    public function scopeOverdue(Builder $query, ?CarbonInterface $moment = null): void
    {
        $query->where('status', ConfirmationStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $moment ?? CarbonImmutable::now());
    }

    /**
     * Whether the confirmation is still pending but its expiry has passed (at the moment,
     * now when omitted): it reads as expired and takes no answer.
     */
    public function isOverdue(?CarbonInterface $moment = null): bool
    {
        return $this->status === ConfirmationStatus::Pending
            && $this->expires_at !== null
            && $this->expires_at->lessThanOrEqualTo($moment ?? CarbonImmutable::now());
    }

    /**
     * Whether it expired — lapsed by the sweep, or past its expiry and not swept yet.
     */
    public function isExpired(?CarbonInterface $moment = null): bool
    {
        return $this->status === ConfirmationStatus::Expired || $this->isOverdue($moment);
    }

    /**
     * Whether the person can still confirm or decline it.
     */
    public function isPending(?CarbonInterface $moment = null): bool
    {
        return $this->status === ConfirmationStatus::Pending && ! $this->isOverdue($moment);
    }

    /**
     * The status as it reads now: a pending confirmation past its expiry reads as expired
     * before the sweep lapses it.
     */
    public function effectiveStatus(?CarbonInterface $moment = null): ConfirmationStatus
    {
        return $this->isOverdue($moment) ? ConfirmationStatus::Expired : $this->status;
    }

    /**
     * Whether the confirmation was asked of this model.
     */
    public function isAddressedTo(Model $person): bool
    {
        return $this->person_type === $person->getMorphClass()
            && self::sameKey($this->person_id, $person->getKey());
    }

    /**
     * Whether the confirmation is about this model.
     */
    public function isFor(Model $subject): bool
    {
        return $this->subject_type === $subject->getMorphClass()
            && self::sameKey($this->subject_id, $subject->getKey());
    }

    /**
     * Whether the hash is the one the confirmation was asked for, compared in constant time.
     */
    public function matchesPayload(string $hash): bool
    {
        return hash_equals($this->payload_hash, $hash);
    }

    /**
     * Move the confirmation from pending to `$outcome` with one conditional update, and
     * read it back. The update only matches a row that is still pending — and, to confirm
     * or decline, not past its expiry; to expire, past it — so of two answers (or an
     * answer and the sweep) racing for the same row exactly one moves it.
     *
     * @internal
     *
     * @return bool whether this call moved it
     */
    public function claim(ConfirmationStatus $outcome, ?string $reason = null, ?CarbonInterface $moment = null): bool
    {
        $moment ??= CarbonImmutable::now();

        $query = $this->newQuery()
            ->whereKey($this->getKey())
            ->where('status', ConfirmationStatus::Pending->value);

        if ($outcome === ConfirmationStatus::Expired) {
            $query->whereNotNull('expires_at')->where('expires_at', '<=', $moment);
        } else {
            $query->where(function (Builder $q) use ($moment): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $moment);
            });
        }

        $attributes = ['status' => $outcome->value, 'resolved_at' => $moment];

        if ($reason !== null) {
            $attributes['reason'] = $reason;
        }

        $claimed = $query->update($attributes) === 1;

        $this->refresh();

        return $claimed;
    }

    /**
     * Lapse the confirmation if it is overdue: it moves to expired and
     * ApprovalConfirmationExpired fires, once, whoever gets there first.
     *
     * @internal
     *
     * @return bool whether this call lapsed it
     */
    public function lapseIfOverdue(?CarbonInterface $moment = null): bool
    {
        $moment ??= CarbonImmutable::now();

        if (! $this->isOverdue($moment) || ! $this->claim(ConfirmationStatus::Expired, null, $moment)) {
            return false;
        }

        ApprovalConfirmationExpired::dispatch($this);

        return true;
    }

    /**
     * Keys compare as strings: a Postgres char(26) ulid column, an int and a numeric
     * string all name the same row.
     */
    private static function sameKey(mixed $stored, mixed $given): bool
    {
        if (! (is_int($stored) || is_string($stored)) || ! (is_int($given) || is_string($given))) {
            return false;
        }

        return rtrim((string) $stored, ' ') === rtrim((string) $given, ' ');
    }

    protected static function newFactory(): ApprovalConfirmationFactory
    {
        return ApprovalConfirmationFactory::new();
    }
}
