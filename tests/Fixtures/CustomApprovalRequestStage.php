<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host subclass `approvals.stage_model` invites.
 *
 * Stages are created by the package on the host's behalf (a staged request opens its
 * own stage rows), never by the caller — so this is the seam most likely to be bypassed
 * by a `static::query()->create()` inside the packaged model, and the one where counting
 * created-events earns its keep.
 */
class CustomApprovalRequestStage extends ApprovalRequestStage
{
    use CountsCreations;

    protected $table = 'approval_request_stages';
}
