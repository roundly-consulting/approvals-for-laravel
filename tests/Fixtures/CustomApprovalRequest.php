<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host subclass `approvals.request_model` invites.
 */
class CustomApprovalRequest extends ApprovalRequest
{
    use CountsCreations;

    protected $table = 'approval_requests';
}
