<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\HasApprovals;

class DeploymentTestModel extends Model
{
    use HasApprovals;

    public $table = 'deployments';

    protected $guarded = [];

    public $timestamps = false;
}
