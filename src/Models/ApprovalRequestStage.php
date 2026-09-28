<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Approvals\Database\Factories\ApprovalRequestStageFactory;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * @property int $id
 * @property int $approval_request_id
 * @property int $position
 * @property string|null $name
 * @property ApprovalRule $rule
 * @property int|null $quorum
 * @property int|null $required_approvers
 * @property array<array-key, mixed>|null $approvers
 * @property ApprovalStatus $status
 * @property CarbonImmutable|null $opened_at
 * @property CarbonImmutable|null $cleared_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class ApprovalRequestStage extends Model
{
    /** @use HasFactory<ApprovalRequestStageFactory> */
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
            'position' => 'integer',
            'opened_at' => 'immutable_datetime',
            'cleared_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequestModelResolver::class(), 'approval_request_id');
    }

    /**
     * Decisions recorded against this stage.
     *
     * @return HasMany<Approval, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalModelResolver::class(), 'approval_request_stage_id');
    }

    /**
     * The approvers this stage names. Empty when it was opened without names — then any
     * approver may decide it.
     *
     * @return list<NamedApprover>
     */
    public function namedApprovers(): array
    {
        return NamedApprover::listFrom($this->approvers);
    }

    public function hasNamedApprover(Model $model): bool
    {
        return NamedApprover::listIncludes($this->namedApprovers(), $model);
    }

    public function isOpen(): bool
    {
        return $this->status === ApprovalStatus::Pending && $this->opened_at !== null;
    }

    public function markOpened(): static
    {
        if ($this->opened_at === null) {
            $this->opened_at = CarbonImmutable::now();
            $this->save();
        }

        return $this;
    }

    protected static function newFactory(): ApprovalRequestStageFactory
    {
        return ApprovalRequestStageFactory::new();
    }
}
