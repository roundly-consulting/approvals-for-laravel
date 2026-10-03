<?php

declare(strict_types=1);

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
    | match them. Any other value throws an InvalidConfigurationException when
    | the migrations run.
    |
    | Supported: "bigint", "uuid", "ulid"
    |
    */

    'key_type' => env('APPROVALS_KEY_TYPE', 'bigint'),

    /*
    |--------------------------------------------------------------------------
    | Authorization
    |--------------------------------------------------------------------------
    |
    | When enabled, every decision path — approve, reject, toggle, ask and
    | cancel — is gated through Laravel's authorization layer: the actor must
    | pass the given Gate ability against the approvable first. Accepts env
    | strings ("true"/"1"/"yes"/"on", "false"/"0"/"no"/"off"); anything else
    | throws an InvalidConfigurationException. The package never defines the
    | gate itself; the host application registers it. "ability" must be a
    | non-empty string (null means "decide-approval"); anything else throws.
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
    | The default lifetime, in seconds, of an approval given without an explicit
    | expiry (->expiresIn() / ->expiringAt()). Once it passes, the approval stops
    | counting and `approvals:expire` lapses it. Null means approvals never
    | expire unless an expiry is set per decision.
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
    |     Approvals::request($subject)->workflow('payout')->open([$a, $b, $c]);
    |
    | A preset is either flat (a single rule/quorum/required_approvers) or staged
    | (a list of stage shapes, each with its own rule/quorum/required_approvers).
    | Optional keys: 'expiry' (positive seconds) and, for staged presets, a
    | non-empty stage 'name' and 'reject_on_stage_rejection' (a boolean or a
    | boolean spelling such as 'false'; defaults to true). A malformed preset
    | throws UnknownWorkflowException; a non-array 'workflows' value throws an
    | InvalidConfigurationException.
    |
    */

    'workflows' => [
        // 'payout' => [
        //     'rule' => 'quorum',
        //     'quorum' => 2,
        //     'required_approvers' => 3,
        //     'expiry' => 86400,
        // ],
        // 'release' => [
        //     'reject_on_stage_rejection' => true,
        //     'stages' => [
        //         ['rule' => 'unanimous', 'required_approvers' => 2, 'name' => 'engineering'],
        //         ['rule' => 'any', 'required_approvers' => 1, 'name' => 'product'],
        //     ],
        // ],
    ],
];
