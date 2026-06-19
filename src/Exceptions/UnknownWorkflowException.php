<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

final class UnknownWorkflowException extends ApprovalsException
{
    public static function named(string $name): self
    {
        return new self("No approval workflow preset named [{$name}] is configured.");
    }

    public static function invalid(string $name, string $detail): self
    {
        return new self("The approval workflow preset [{$name}] is invalid: {$detail}");
    }
}
