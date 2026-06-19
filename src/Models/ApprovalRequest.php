<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Approvals\Database\Factories\ApprovalRequestFactory;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalProgress;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStageCleared;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestStageModelResolver;
use RoundlyConsulting\Approvals\Support\RuleEvaluator;

/**
 * @property int $id
 * @property int|null $subject_id
 * @property string|null $subject_type
 * @property ApprovalRule $rule
 * @property int|null $quorum
 * @property int|null $required_approvers
 * @property bool $staged
 * @property bool $reject_on_stage_rejection
 * @property string|null $workflow
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
            'staged' => 'boolean',
            'reject_on_stage_rejection' => 'boolean',
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
     * @return HasMany<ApprovalRequestStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ApprovalRequestStageModelResolver::class(), 'approval_request_id')
            ->orderBy('position');
    }

    /**
     * The stage currently open for decisions, if the request is staged and still pending.
     */
    public function currentStage(): ?ApprovalRequestStage
    {
        if (! $this->staged) {
            return null;
        }

        return $this->stages()
            ->where('status', ApprovalStatus::Pending)
            ->orderBy('position')
            ->first();
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

        return $this->staged ? $this->resolveStaged() : $this->resolveFlat();
    }

    private function resolveFlat(): static
    {
        [$approvals, $rejections] = $this->tally($this->decisions()->get());

        $outcome = app(RuleEvaluator::class)->evaluate(
            $this->rule,
            $approvals,
            $rejections,
            $this->required_approvers ?? 0,
            $this->quorum,
        );

        if ($outcome === null) {
            return $this;
        }

        $this->finalize($outcome);

        return $this;
    }

    private function resolveStaged(): static
    {
        $evaluator = app(RuleEvaluator::class);

        /** @var Collection<int, ApprovalRequestStage> $stages */
        $stages = $this->stages()->get();

        foreach ($stages as $stage) {
            if ($stage->status->isFinal()) {
                continue;
            }

            $stage->markOpened();

            [$approvals, $rejections] = $this->tally($stage->decisions()->get());

            $outcome = $evaluator->evaluate(
                $stage->rule,
                $approvals,
                $rejections,
                $stage->required_approvers ?? 0,
                $stage->quorum,
            );

            if ($outcome === null) {
                // The earliest unresolved stage is the gate: stop here.
                return $this;
            }

            if ($outcome === ApprovalStatus::Rejected) {
                $stage->status = ApprovalStatus::Rejected;
                $stage->cleared_at = CarbonImmutable::now();
                $stage->save();

                if ($this->reject_on_stage_rejection) {
                    $this->finalize(ApprovalStatus::Rejected);

                    return $this;
                }

                continue;
            }

            $stage->status = ApprovalStatus::Approved;
            $stage->cleared_at = CarbonImmutable::now();
            $stage->save();

            ApprovalStageCleared::dispatch($stage);

            $next = $this->stages()
                ->where('status', ApprovalStatus::Pending)
                ->orderBy('position')
                ->first();

            if ($next instanceof ApprovalRequestStage) {
                $next->markOpened();
                ApprovalStageOpened::dispatch($next);
            }
        }

        // Every stage settled without an early rejection: the request is approved when
        // at least one stage cleared as approved, otherwise rejected.
        $cleared = $this->stages()->where('status', ApprovalStatus::Approved)->exists();

        $this->finalize($cleared ? ApprovalStatus::Approved : ApprovalStatus::Rejected);

        return $this;
    }

    /**
     * Tally the summed approval/rejection weight of a decision set.
     *
     * @param  Collection<int, Approval>  $decisions
     * @return array{0: int, 1: int}
     */
    private function tally(Collection $decisions): array
    {
        $approvals = (int) $decisions
            ->where('status', ApprovalStatus::Approved)
            ->sum('weight');

        $rejections = (int) $decisions
            ->where('status', ApprovalStatus::Rejected)
            ->sum('weight');

        return [$approvals, $rejections];
    }

    private function finalize(ApprovalStatus $outcome): void
    {
        $from = $this->status;

        $this->status = $outcome;
        $this->resolved_at = CarbonImmutable::now();
        $this->save();

        ApprovalRequestResolved::dispatch($this);
        ApprovalStatusChanged::dispatch($this, $from, $outcome);
    }

    /**
     * A snapshot of how far the request has progressed.
     */
    public function approvalProgress(): ApprovalProgress
    {
        if ($this->staged) {
            return $this->stagedProgress();
        }

        [$approvals, $rejections] = $this->tally($this->decisions()->get());

        return new ApprovalProgress(
            status: $this->status,
            approved: $approvals,
            rejected: $rejections,
            required: $this->required_approvers ?? 0,
            threshold: $this->quorum,
            currentStage: null,
            totalStages: 0,
            clearedStages: 0,
        );
    }

    private function stagedProgress(): ApprovalProgress
    {
        /** @var Collection<int, ApprovalRequestStage> $stages */
        $stages = $this->stages()->get();

        $cleared = $stages->where('status', ApprovalStatus::Approved)->count();
        $current = $this->currentStage();

        $approvals = 0;
        $rejections = 0;
        $required = 0;
        $threshold = null;

        if ($current instanceof ApprovalRequestStage) {
            [$approvals, $rejections] = $this->tally($current->decisions()->get());
            $required = $current->required_approvers ?? 0;
            $threshold = $current->quorum;
        }

        return new ApprovalProgress(
            status: $this->status,
            approved: $approvals,
            rejected: $rejections,
            required: $required,
            threshold: $threshold,
            currentStage: $current?->position,
            totalStages: $stages->count(),
            clearedStages: $cleared,
        );
    }

    protected static function newFactory(): ApprovalRequestFactory
    {
        return ApprovalRequestFactory::new();
    }
}
