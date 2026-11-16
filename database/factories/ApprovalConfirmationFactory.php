<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;
use RoundlyConsulting\Approvals\Support\ApprovalConfirmationModelResolver;

/**
 * @extends Factory<ApprovalConfirmation>
 */
final class ApprovalConfirmationFactory extends Factory
{
    protected $model = ApprovalConfirmation::class;

    /**
     * Build the configured model, not the packaged one: a host that swaps
     * `approvals.confirmation_model` gets its own class, with its casts,
     * events and observers.
     *
     * @return class-string<ApprovalConfirmation>
     */
    public function modelName(): string
    {
        return ApprovalConfirmationModelResolver::class();
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'subject_id' => $this->faker->numberBetween(1, 1000),
            'subject_type' => 'release',
            'person_id' => $this->faker->numberBetween(1, 1000),
            'person_type' => 'actor',
            'payload_hash' => $this->faker->sha256(),
            'status' => ConfirmationStatus::Pending,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function about(Model $subject): static
    {
        return $this->state(fn (): array => [
            'subject_id' => $subject->getKey(),
            'subject_type' => $subject->getMorphClass(),
        ]);
    }

    public function askedOf(Model $person): static
    {
        return $this->state(fn (): array => [
            'person_id' => $person->getKey(),
            'person_type' => $person->getMorphClass(),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
