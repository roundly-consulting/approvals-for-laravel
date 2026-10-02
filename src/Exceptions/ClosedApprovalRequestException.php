<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * A decision (approve, reject, toggle, ask) or a withdrawal reached a request whose
 * round is closed — approved, rejected, cancelled or expired. Nothing was recorded.
 */
final class ClosedApprovalRequestException extends ApprovalsException
{
    private function __construct(
        public readonly ApprovalRequest $request,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(ApprovalRequest $request): self
    {
        return new self(
            $request,
            'Approval request ['.$request->getKey().'] is closed ('.$request->status->value.') and accepts no more decisions.',
        );
    }
}
