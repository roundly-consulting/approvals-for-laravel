<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

/**
 * A confirmation could not be asked for as described: no person or payload hash, an
 * unsaved subject or person, or an expiry that is not in the future. Nothing was recorded.
 */
final class InvalidConfirmationException extends ApprovalsException
{
    public static function missingPerson(): self
    {
        return new self('No person was set on the pending confirmation. Call by() first.');
    }

    public static function missingPayload(): self
    {
        return new self('No payload hash was set on the pending confirmation. Call payload() first.');
    }

    public static function invalidPayload(): self
    {
        return new self('A confirmation payload hash must be a non-blank string of at most 255 characters.');
    }

    /**
     * @param  'subject'|'person'  $role
     */
    public static function unsaved(string $role): self
    {
        return new self("The confirmation {$role} must be a saved model.");
    }

    public static function expiryNotInFuture(): self
    {
        return new self('A confirmation expiry must be in the future.');
    }
}
