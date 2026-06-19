<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

final class IncompletePendingApprovalException extends ApprovalsException
{
    public static function missingActor(): self
    {
        return new self('No actor was set on the pending approval. Call as() first.');
    }

    public static function missingApprovable(): self
    {
        return new self('No approvable was set on the pending approval. Call for() first.');
    }
}
