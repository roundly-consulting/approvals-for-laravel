<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Enums;

use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Testing\ApprovalsFake;
use RoundlyConsulting\Enums\Helpers;

/**
 * Every state-changing operation the approvals API performs. Each one runs through
 * {@see ApprovalsManager::perform()}, which is how
 * {@see ApprovalsFake} sees calls made through
 * the facade, its builders and the model traits alike.
 */
enum ApprovalOperation: string
{
    use Helpers;

    /** An actor approved an approvable. */
    case Approve = 'approve';

    /** An actor rejected an approvable. */
    case Reject = 'reject';

    /** A pending decision was requested from one actor. */
    case Ask = 'ask';

    /** An actor withdrew its active decision. */
    case Cancel = 'cancel';

    /** An actor toggled its approval on or off. */
    case Toggle = 'toggle';

    /** A multi-approver request (flat, staged or from a workflow preset) was opened. */
    case Open = 'open';

    /** A delegator handed its approval authority to a delegate. */
    case Delegate = 'delegate';

    /** A delegator revoked one or more delegations. */
    case Revoke = 'revoke';

    /** Expired pending decisions were lapsed. */
    case Expire = 'expire';

    /** A subject's open request was closed from outside, as cancelled or expired. */
    case Close = 'close';
}
