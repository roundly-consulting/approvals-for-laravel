<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host subclass `approvals.confirmation_model` invites.
 */
class CustomApprovalConfirmation extends ApprovalConfirmation
{
    use CountsCreations;

    protected $table = 'approval_confirmations';
}
