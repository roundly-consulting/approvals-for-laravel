<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Approvals\Database\Factories\ApprovalRequestFactory;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

/**
 * @property int $id
 * @property int|null $subject_id
 * @property string|null $subject_type
 * @property ApprovalRule $rule
 * @property int|null $quorum
 * @property int|null $required_approvers
 * @property ApprovalStatus $status
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class ApprovalRequest extends Model
{
    /** @use HasFactory<ApprovalRequestFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule' => ApprovalRule::class,
            'status' => ApprovalStatus::class,
            'resolved_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<Approval, $this>
     */
    public function decisions(): MorphMany
    {
        return $this->morphMany(ApprovalModelResolver::class(), 'approval_request');
    }

    /**
     * Evaluate the request's rule against its current decisions and persist the outcome.
     *
     * Resolution is idempotent: once a request is final it is returned unchanged.
     */
    public function resolve(): static
    {
        if ($this->status->isFinal()) {
            return $this;
        }

        /** @var Collection<int, Approval> $decisions */
        $decisions = $this->decisions()->get();

        $approvals = $decisions->where('status', ApprovalStatus::Approved)->count();
        $rejections = $decisions->where('status', ApprovalStatus::Rejected)->count();

        $outcome = $this->evaluate($approvals, $rejections);

        if ($outcome === null) {
            return $this;
        }

        $this->status = $outcome;
        $this->resolved_at = CarbonImmutable::now();
        $this->save();

        ApprovalRequestResolved::dispatch($this);

        return $this;
    }

    private function evaluate(int $approvals, int $rejections): ?ApprovalStatus
    {
        $required = $this->required_approvers ?? 0;

        return match ($this->rule) {
            ApprovalRule::Any => $this->evaluateAny($approvals, $rejections, $required),
            ApprovalRule::Unanimous => $this->evaluateUnanimous($approvals, $rejections, $required),
            ApprovalRule::Quorum => $this->evaluateQuorum($approvals, $rejections, $required),
        };
    }

    private function evaluateAny(int $approvals, int $rejections, int $required): ?ApprovalStatus
    {
        if ($approvals >= 1) {
            return ApprovalStatus::Approved;
        }

        if ($required > 0 && $rejections >= $required) {
            return ApprovalStatus::Rejected;
        }

        return null;
    }

    private function evaluateUnanimous(int $approvals, int $rejections, int $required): ?ApprovalStatus
    {
        if ($rejections >= 1) {
            return ApprovalStatus::Rejected;
        }

        if ($required > 0 && $approvals >= $required) {
            return ApprovalStatus::Approved;
        }

        return null;
    }

    private function evaluateQuorum(int $approvals, int $rejections, int $required): ?ApprovalStatus
    {
        $quorum = $this->quorum ?? $required;

        if ($quorum > 0 && $approvals >= $quorum) {
            return ApprovalStatus::Approved;
        }

        // Reject as soon as the quorum can no longer be reached (Decision D-5).
        if ($required > 0 && ($required - $rejections) < $quorum) {
            return ApprovalStatus::Rejected;
        }

        return null;
    }

    protected static function newFactory(): ApprovalRequestFactory
    {
        return ApprovalRequestFactory::new();
    }
}
