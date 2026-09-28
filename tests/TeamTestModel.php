<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;
use RoundlyConsulting\Approvals\Traits\HasApprovals;
use RoundlyConsulting\Approvals\Traits\RequiresApproval;

/**
 * A model that both decides and is decided on (a team approving others' work while its
 * own membership changes need sign-off), so it carries all three traits at once.
 */
class TeamTestModel extends Model
{
    use GivesApprovals;
    use HasApprovals;
    use RequiresApproval;

    public $table = 'teams';

    protected $guarded = [];

    public $timestamps = false;
}
