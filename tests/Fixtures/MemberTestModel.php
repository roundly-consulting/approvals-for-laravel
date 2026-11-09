<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;

/**
 * An approver who can leave: soft-deleting it takes it out of every round it was named on.
 */
class MemberTestModel extends Model
{
    use GivesApprovals;
    use SoftDeletes;

    public $table = 'members';

    protected $guarded = [];

    public $timestamps = false;
}
