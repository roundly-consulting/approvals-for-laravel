<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

/**
 * @extends Factory<ApprovalRequest>
 */
final class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    /**
     * Build the configured model, not the packaged one: a host that swaps
     * `approvals.request_model` gets its own class, with its casts,
     * events and observers.
     *
     * @return class-string<ApprovalRequest>
     */
    public function modelName(): string
    {
        return ApprovalRequestModelResolver::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => $this->faker->numberBetween(1, 1000),
            'subject_type' => 'subject',
            'rule' => ApprovalRule::Unanimous,
            'required_approvers' => 1,
            'status' => ApprovalStatus::Pending,
        ];
    }

    public function unanimous(): static
    {
        return $this->state(fn (): array => ['rule' => ApprovalRule::Unanimous]);
    }

    public function quorum(int $quorum): static
    {
        return $this->state(fn (): array => [
            'rule' => ApprovalRule::Quorum,
            'quorum' => $quorum,
        ]);
    }

    public function any(): static
    {
        return $this->state(fn (): array => ['rule' => ApprovalRule::Any]);
    }

    public function weighted(int $threshold): static
    {
        return $this->state(fn (): array => [
            'rule' => ApprovalRule::Weighted,
            'quorum' => $threshold,
        ]);
    }

    public function staged(): static
    {
        return $this->state(fn (): array => ['staged' => true]);
    }

    public function forSubject(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_id' => $subject->getKey(),
            'subject_type' => $subject->getMorphClass(),
        ]);
    }
}
