<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use RoundlyConsulting\Approvals\Tests\TestCase;

/**
 * The suite's base case with all four `approvals.*_model` seams already pointed at the
 * host subclasses BEFORE the providers boot.
 *
 * Boot order is the whole point: the provider hangs its wiring on whatever the config
 * names at boot, and the models resolve each other at boot too (an ApprovalRequest's
 * `stages()` relation asks ApprovalRequestStageModelResolver for a class). A
 * `config()->set()` inside a test body reads back correctly while leaving every listener
 * and relation on the packaged class — the shape that let media #28 ship.
 *
 * All four are swapped together rather than one per case, because they resolve *each
 * other*: swapping only `request_model` would leave the request's own `stages()` and
 * `decisions()` relations pointing at packaged classes, and a per-seam base case would
 * prove less while costing four boots.
 *
 * Note `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently discard
 * whatever the base case wires — the same decapitation an un-parented `defineEnvironment()`
 * causes one level up. The parent is empty today; that is not a reason to omit it.
 */
abstract class SwappedApprovalsTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'approvals.model' => CustomApproval::class,
            'approvals.request_model' => CustomApprovalRequest::class,
            'approvals.stage_model' => CustomApprovalRequestStage::class,
            'approvals.delegation_model' => CustomApprovalDelegation::class,
        ]);
    }
}
