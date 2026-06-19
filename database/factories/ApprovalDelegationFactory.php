<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;

/**
 * @extends Factory<ApprovalDelegation>
 */
final class ApprovalDelegationFactory extends Factory
{
    protected $model = ApprovalDelegation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delegator_id' => $this->faker->numberBetween(1, 1000),
            'delegator_type' => 'actor',
            'delegate_id' => $this->faker->numberBetween(1, 1000),
            'delegate_type' => 'actor',
            'starts_at' => null,
            'ends_at' => null,
            'revoked_at' => null,
        ];
    }

    public function forDelegator(Model $delegator): static
    {
        return $this->state(fn (): array => [
            'delegator_id' => $delegator->getKey(),
            'delegator_type' => $delegator->getMorphClass(),
        ]);
    }

    public function toDelegate(Model $delegate): static
    {
        return $this->state(fn (): array => [
            'delegate_id' => $delegate->getKey(),
            'delegate_type' => $delegate->getMorphClass(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
