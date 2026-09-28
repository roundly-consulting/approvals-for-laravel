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
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionTally;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
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
 * @property array<array-key, mixed>|null $approvers
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
            'approvers' => 'array',
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
     * The approvers a flat request names. Empty when it was opened without names — then
     * any approver may decide. A staged request names its approvers per stage.
     *
     * @return list<NamedApprover>
     */
    public function namedApprovers(): array
    {
        return NamedApprover::listFrom($this->approvers);
    }

    /**
     * The approvers who may decide right now: the open stage's for a staged request,
     * the request's own for a flat one. Empty means any approver may decide.
     *
     * @return list<NamedApprover>
     */
    public function approversFor(?ApprovalRequestStage $stage = null): array
    {
        if ($stage instanceof ApprovalRequestStage) {
            return $stage->namedApprovers();
        }

        return $this->staged ? [] : $this->namedApprovers();
    }

    /**
     * Whether the request (or its open stage, for a staged request) names `$model` as
     * an approver.
     */
    public function hasNamedApprover(Model $model): bool
    {
        return NamedApprover::listIncludes($this->approversFor($this->currentStage()), $model);
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
        $outcome = app(RuleEvaluator::class)->evaluate($this->rule, $this->flatTally());

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

            $outcome = $evaluator->evaluate($stage->rule, $this->stageTally($stage));

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

    private function flatTally(): DecisionTally
    {
        return $this->tally(
            $this->decisions()->get(),
            $this->rule,
            $this->required_approvers ?? 0,
            $this->quorum,
            $this->namedApprovers(),
        );
    }

    private function stageTally(ApprovalRequestStage $stage): DecisionTally
    {
        return $this->tally(
            $stage->decisions()->get(),
            $stage->rule,
            $stage->required_approvers ?? 0,
            $stage->quorum,
            $stage->namedApprovers(),
        );
    }

    /**
     * Tally a decision set: approvals and rejections by headcount and by weight, and
     * who is still to decide.
     *
     * With named approvers, the outstanding ones are those without a decision, and the
     * weight they could still add is the weight each carried when the request opened.
     * Without names only a headcount is known; each outstanding slot counts as weight 1,
     * except under the Weighted rule, where an unnamed approver's weight is unknowable.
     *
     * @param  Collection<int, Approval>  $decisions
     * @param  list<NamedApprover>  $named
     */
    private function tally(Collection $decisions, ApprovalRule $rule, int $required, ?int $quorum, array $named): DecisionTally
    {
        $decided = $decisions->filter(
            static fn (Approval $decision): bool => $decision->status === ApprovalStatus::Approved
                || $decision->status === ApprovalStatus::Rejected,
        );

        $approved = $decided->where('status', ApprovalStatus::Approved);
        $rejected = $decided->where('status', ApprovalStatus::Rejected);

        if ($named === []) {
            $outstandingCount = max(0, $required - $decided->count());
            $outstandingWeight = $rule === ApprovalRule::Weighted ? null : $outstandingCount;
        } else {
            $outstanding = array_filter(
                $named,
                static fn (NamedApprover $approver): bool => ! $decided->contains(
                    static fn (Approval $decision): bool => $approver->isActorOf($decision),
                ),
            );

            $outstandingCount = count($outstanding);
            $outstandingWeight = array_sum(array_map(static fn (NamedApprover $approver): int => $approver->weight, $outstanding));
        }

        return new DecisionTally(
            approvedCount: $approved->count(),
            rejectedCount: $rejected->count(),
            approvedWeight: (int) $approved->sum('weight'),
            rejectedWeight: (int) $rejected->sum('weight'),
            required: $required,
            quorum: $quorum,
            outstandingCount: $outstandingCount,
            outstandingWeight: $outstandingWeight,
        );
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

        $tally = $this->flatTally();

        return new ApprovalProgress(
            status: $this->status,
            approved: $this->rule->isWeighted() ? $tally->approvedWeight : $tally->approvedCount,
            rejected: $this->rule->isWeighted() ? $tally->rejectedWeight : $tally->rejectedCount,
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
            $tally = $this->stageTally($current);
            $approvals = $current->rule->isWeighted() ? $tally->approvedWeight : $tally->approvedCount;
            $rejections = $current->rule->isWeighted() ? $tally->rejectedWeight : $tally->rejectedCount;
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
