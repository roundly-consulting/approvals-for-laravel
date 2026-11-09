<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * A decision reached a round whose subject changed since the round opened (its
 * ProvidesApprovalFingerprint value no longer matches), with
 * `approvals.fingerprint.on_change` set to `refuse` or `void`.
 */
final class ChangedApprovalSubjectException extends ApprovalsException
{
    /**
     * @param  bool  $voided  whether the round was voided on the way (`on_change` is `void`)
     */
    private function __construct(
        string $message,
        public readonly ApprovalRequest $request,
        public readonly bool $voided,
    ) {
        parent::__construct($message);
    }

    public static function for(ApprovalRequest $request, bool $voided): self
    {
        return new self(
            'The subject of approval request ['.$request->getKey().'] changed after the round opened, so it takes no decision'
            .($voided ? ': the round was voided. Open a new round for the changed subject.' : '. Void it with voidIfChanged() and open a new one.'),
            $request,
            $voided,
        );
    }
}
