<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApproval;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApprovalDelegation;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApprovalRequest;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApprovalRequestStage;

/**
 * The shipped factories build the model the host configured. A factory without a `modelName()`
 * override builds its packaged `$model`, so a host seeding through `Approval::factory()` got
 * packaged rows — without its own casts, events or observers. Exact classes, not `instanceof`:
 * the custom models extend the packaged ones, so `toBeInstanceOf` passes either way.
 */
it('builds the configured approval and request models', function (): void {
    expect(Approval::factory()->create()::class)->toBe(CustomApproval::class)
        ->and(CustomApproval::factory()->create()::class)->toBe(CustomApproval::class)
        ->and(ApprovalRequest::factory()->create()::class)->toBe(CustomApprovalRequest::class);
});

it('builds the configured stage and delegation models', function (): void {
    expect(ApprovalRequestStage::factory()->create()::class)->toBe(CustomApprovalRequestStage::class)
        ->and(ApprovalDelegation::factory()->create()::class)->toBe(CustomApprovalDelegation::class);
});

it('fires the host model events for the request a stage factory creates', function (): void {
    // Reading the stage's request back resolves through the seam either way, so the oracle is
    // the host class's own `created` event: it fires only for a row created as that class.
    CustomApprovalRequest::resetCreationCount();

    ApprovalRequestStage::factory()->create();

    expect(CustomApprovalRequest::creationCount())->toBe(1);
});
