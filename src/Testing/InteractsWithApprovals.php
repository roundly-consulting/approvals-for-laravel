<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Testing;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\ApprovalsManager;

/**
 * Opt-in testing ergonomics for host applications. Use it from a Pest/PHPUnit test case:
 *
 *     uses(RoundlyConsulting\Approvals\Testing\InteractsWithApprovals::class);
 *
 * It is intentionally framework-light and pulls in no runtime dependency on Pest. The
 * helpers go through the approvals manager, so they are recorded under Approvals::fake().
 */
trait InteractsWithApprovals
{
    private ?Model $actingApprover = null;

    /**
     * Remember an actor so subsequent helper calls can omit it.
     */
    public function actingAsApprover(Model $actor): static
    {
        $this->actingApprover = $actor;

        return $this;
    }

    /**
     * Approve a model as the remembered (or given) actor.
     */
    public function approveAs(Model $approvable, ?string $reason = null, ?Model $actor = null): void
    {
        app(ApprovalsManager::class)
            ->for($approvable)
            ->as($this->approver($actor))
            ->because($reason)
            ->approve();
    }

    /**
     * Reject a model as the remembered (or given) actor.
     */
    public function rejectAs(Model $approvable, ?string $reason = null, ?Model $actor = null): void
    {
        app(ApprovalsManager::class)
            ->for($approvable)
            ->as($this->approver($actor))
            ->because($reason)
            ->reject();
    }

    private function approver(?Model $actor): Model
    {
        $approver = $actor ?? $this->actingApprover;

        if ($approver === null) {
            throw new \RuntimeException('No approver set. Call actingAsApprover() first or pass an actor.');
        }

        return $approver;
    }
}
