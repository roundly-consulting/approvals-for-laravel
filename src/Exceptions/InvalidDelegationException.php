<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use Carbon\CarbonInterface;

final class InvalidDelegationException extends ApprovalsException
{
    public static function selfDelegation(): self
    {
        return new self('An approver cannot delegate approval authority to itself.');
    }

    public static function endsBeforeStart(CarbonInterface $startsAt, CarbonInterface $endsAt): self
    {
        return new self(
            "A delegation cannot end at [{$endsAt->toIso8601String()}] before it starts at [{$startsAt->toIso8601String()}]."
        );
    }
}
