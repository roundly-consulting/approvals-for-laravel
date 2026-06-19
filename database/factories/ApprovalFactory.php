<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
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
        ];
    }
}
