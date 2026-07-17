<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * The host subclass `approvals.model` invites, used to prove the seam is real.
 *
 * `CountsCreations` is what makes the proof independent of `instanceof`: it counts rows
 * created as *this exact class*, so a decision row created as the packaged Approval —
 * which would still satisfy `instanceof` while firing none of the host's model events
 * (permissions #31) — cannot be mistaken for an honoured swap.
 */
class CustomApproval extends Approval
{
    use CountsCreations;

    protected $table = 'approvals';
}
