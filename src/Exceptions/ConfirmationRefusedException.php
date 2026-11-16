<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

use RoundlyConsulting\Approvals\Enums\ConfirmationRefusal;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

/**
 * A confirmation was not confirmed (or declined): the hash did not match, it was used or
 * declined already, it expired, or the answering model is not its person. Nothing was
 * recorded. The message is translated; errorCode() is the stable code for a client.
 */
final class ConfirmationRefusedException extends ApprovalsException
{
    private function __construct(
        public readonly ConfirmationRefusal $refusal,
        public readonly ApprovalConfirmation $confirmation,
    ) {
        parent::__construct($refusal->message());
    }

    public static function because(ConfirmationRefusal $refusal, ApprovalConfirmation $confirmation): self
    {
        return new self($refusal, $confirmation);
    }

    /**
     * The stable error code: `mismatch`, `used`, `expired`, `not_the_person` or `declined`.
     */
    public function errorCode(): string
    {
        return $this->refusal->value;
    }
}
