<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;

/**
 * @extends Factory<Approval>
 */
final class ApprovalFactory extends Factory
{
    protected $model = Approval::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => $this->faker->numberBetween(1, 1000),
            'actor_type' => 'actor',
            'approvable_id' => $this->faker->numberBetween(1, 1000),
            'approvable_type' => 'approvable',
            'status' => ApprovalStatus::Approved,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => ApprovalStatus::Pending]);
    }

    public function approved(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Approved,
            'decided_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Rejected,
            'decided_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Cancelled,
            'decided_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Expired,
            'decided_at' => now(),
        ]);
    }

    public function weight(int $weight): static
    {
        return $this->state(fn (): array => ['weight' => $weight]);
    }

    public function delegated(Model $decidedBy): static
    {
        return $this->state(fn (): array => [
            'decided_by_id' => $decidedBy->getKey(),
            'decided_by_type' => $decidedBy->getMorphClass(),
        ]);
    }

    public function forStage(Model $stage): static
    {
        return $this->state(fn (): array => [
            'approval_request_stage_id' => $stage->getKey(),
        ]);
    }

    public function forActor(Model $actor): static
    {
        return $this->state(fn (): array => [
            'actor_id' => $actor->getKey(),
            'actor_type' => $actor->getMorphClass(),
        ]);
    }

    public function forApprovable(Model $approvable): static
    {
        return $this->state(fn (): array => [
            'approvable_id' => $approvable->getKey(),
            'approvable_type' => $approvable->getMorphClass(),
        ]);
    }
}
