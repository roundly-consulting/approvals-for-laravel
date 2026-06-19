<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;

class ActorTestModel extends Model
{
    use GivesApprovals;

    public $table = 'actors';

    protected $guarded = [];

    public $timestamps = false;
}
