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
}
