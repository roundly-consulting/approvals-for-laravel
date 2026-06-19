<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use Illuminate\Database\Eloquent\Model;

final class UnauthorizedApprovalException extends ApprovalsException
{
    public static function forActor(Model $actor): self
    {
        return new self(
            'The actor ['.$actor::class.'] is not authorized to decide on this approval.'
        );
    }
}
