<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Interfaces\ProvidesApprovalFingerprint;
use RoundlyConsulting\Approvals\Traits\HasApprovals;
use RoundlyConsulting\Approvals\Traits\RequiresApproval;

/**
 * A subject approvers sign off on one version of: its amount.
 *
 * @property int $id
 * @property int $amount
 * @property int|null $author_id
 */
class InvoiceTestModel extends Model implements ProvidesApprovalFingerprint
{
    use HasApprovals;
    use RequiresApproval;

    public $table = 'invoices';

    protected $guarded = [];

    public $timestamps = false;

    public function approvalFingerprint(): string
    {
        return 'amount:'.$this->amount;
    }
}
