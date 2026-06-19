<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Interfaces\ProvidesApprovalWeight;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;

class WeightedReviewerTestModel extends Model implements ProvidesApprovalWeight
{
    use GivesApprovals;

    public $table = 'actors';

    protected $guarded = [];

    public $timestamps = false;

    public int $weight = 1;

    public function approvalWeight(?Model $approvable = null): int
    {
        return $this->weight;
    }
}
