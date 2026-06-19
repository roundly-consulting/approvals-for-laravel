<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

return [
    /*
    |--------------------------------------------------------------------------
    | Approval model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store approval decisions. Override this if you
    | need to extend the default model with your own behaviour. The replacement
    | must extend RoundlyConsulting\Approvals\Models\Approval.
    |
    */

    'model' => Approval::class,

    /*
    |--------------------------------------------------------------------------
    | Approval request model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store multi-approver approval requests. The
    | replacement must extend RoundlyConsulting\Approvals\Models\ApprovalRequest.
    |
    */

    'request_model' => ApprovalRequest::class,

    /*
    |--------------------------------------------------------------------------
    | Default status
    |--------------------------------------------------------------------------
    |
    | The status applied to a newly toggled approval. Defaults to "approved" so
    | the legacy toggle behaviour (a created row means approved) is preserved.
    |
    */

    'default_status' => ApprovalStatus::Approved->value,

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | When enabled, every decision is gated through Laravel's authorization
    | layer: the actor must pass the given Gate ability against the approvable
    | before a decision is recorded. The package never defines the gate itself;
    | the host application registers it.
    |
    */

    'authorization' => [
        'enabled' => env('APPROVALS_AUTHORIZATION', false),
        'ability' => 'decide-approval',
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry
    |--------------------------------------------------------------------------
    |
    | The default lifetime, in seconds, for an approval. Null means approvals
    | never expire unless an explicit expiry is set per decision.
    |
    */

    'expiry' => [
        'default' => null,
    ],
];
