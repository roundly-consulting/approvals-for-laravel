<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;

final class InvalidStatusTransitionException extends ApprovalsException
{
    public static function between(ApprovalStatus $from, ApprovalStatus $to): self
    {
        return new self(
            "Cannot transition an approval from [{$from->value}] to [{$to->value}]."
        );
    }

    /**
     * A round was closed from outside with an outcome other than cancelled or expired.
     * Approved and rejected come only from the approvers' decisions.
     */
    public static function notAClosingOutcome(ApprovalStatus $outcome): self
    {
        return new self(
            "An approval request can only be closed as [cancelled] or [expired], [{$outcome->value}] given: "
            .'approved and rejected come from its approvers\' decisions.'
        );
    }
}
