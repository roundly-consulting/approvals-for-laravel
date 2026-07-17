<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host subclass `approvals.delegation_model` invites.
 */
class CustomApprovalDelegation extends ApprovalDelegation
{
    use CountsCreations;

    protected $table = 'approval_delegations';
}
