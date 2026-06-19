<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Models\Approval;

return [
    /*
    |--------------------------------------------------------------------------
    | Approval model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store approvals. Override this if you need to
    | extend the default model with your own behaviour. The replacement must
    | extend RoundlyConsulting\Approvals\Models\Approval.
    |
    */

    'model' => Approval::class,
];
