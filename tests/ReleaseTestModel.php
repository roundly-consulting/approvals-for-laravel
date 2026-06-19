<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\HasApprovals;
use RoundlyConsulting\Approvals\Traits\RequiresApproval;

class ReleaseTestModel extends Model
{
    use HasApprovals;
    use RequiresApproval;

    public $table = 'releases';

    protected $guarded = [];

    public $timestamps = false;
}
