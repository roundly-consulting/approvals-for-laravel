<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

/**
 * @extends Factory<ApprovalRequestStage>
 */
final class ApprovalRequestStageFactory extends Factory
{
    protected $model = ApprovalRequestStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'approval_request_id' => ApprovalRequestFactory::new()->state(['staged' => true]),
            'position' => 1,
            'name' => null,
            'rule' => ApprovalRule::Unanimous,
            'required_approvers' => 1,
            'status' => ApprovalStatus::Pending,
            'opened_at' => now(),
        ];
    }

    public function forRequest(ApprovalRequest $request): static
    {
        return $this->state(fn (): array => ['approval_request_id' => $request->getKey()]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    public function quorum(int $quorum): static
    {
        return $this->state(fn (): array => [
            'rule' => ApprovalRule::Quorum,
            'quorum' => $quorum,
        ]);
    }

    public function cleared(): static
    {
        return $this->state(fn (): array => [
            'status' => ApprovalStatus::Approved,
            'cleared_at' => now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => ApprovalStatus::Pending]);
    }
}
