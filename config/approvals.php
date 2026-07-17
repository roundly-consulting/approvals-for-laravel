<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

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
    | Approval request stage model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store the stages of a sequential (staged)
    | approval request. The replacement must extend
    | RoundlyConsulting\Approvals\Models\ApprovalRequestStage.
    |
    */

    'stage_model' => ApprovalRequestStage::class,

    /*
    |--------------------------------------------------------------------------
    | Approval delegation model
    |--------------------------------------------------------------------------
    |
    | The Eloquent model used to store approval delegations (proxy authority).
    | The replacement must extend
    | RoundlyConsulting\Approvals\Models\ApprovalDelegation.
    |
    */

    'delegation_model' => ApprovalDelegation::class,

    /*
    |--------------------------------------------------------------------------
    | Key type
    |--------------------------------------------------------------------------
    |
    | The key type used for the polymorphic actor / approvable / approval_request
    | / subject / decided_by / delegator / delegate columns. Use "uuid" or "ulid"
    | when the models an approval points at use UUID/ULID primary keys, otherwise
    | leave it as "bigint". Your morph targets must share one key type; set this to
    | match them. Any unrecognized value falls back to "bigint".
    |
    | Supported: "bigint", "uuid", "ulid"
    |
    */

    'key_type' => env('APPROVALS_KEY_TYPE', 'bigint'),

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

    /*
    |--------------------------------------------------------------------------
    | Workflow presets
    |--------------------------------------------------------------------------
    |
    | Named, reusable approval workflows so apps avoid repeating the same rule,
    | quorum, stage, and expiry wiring at every call site. Open a request with
    | a preset via:
    |
    |     Approvals::for($subject)->workflow('payout')->request([$a, $b]);
    |
    | A preset is either flat (a single rule/quorum/required_approvers) or staged
    | (a list of stage shapes, each with its own rule/quorum/required_approvers).
    | Optional keys: 'expiry' (seconds) and, for staged presets,
    | 'reject_on_stage_rejection' (defaults to true).
    |
    */

    'workflows' => [
        // 'payout' => [
        //     'rule' => ApprovalRule::Quorum->value,
        //     'quorum' => 2,
        //     'required_approvers' => 3,
        //     'expiry' => 86400,
        // ],
        // 'release' => [
        //     'reject_on_stage_rejection' => true,
        //     'stages' => [
        //         ['rule' => ApprovalRule::Unanimous->value, 'required_approvers' => 2, 'name' => 'engineering'],
        //         ['rule' => ApprovalRule::Any->value, 'required_approvers' => 1, 'name' => 'product'],
        //     ],
        // ],
    ],
];
