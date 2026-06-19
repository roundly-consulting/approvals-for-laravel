<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Exceptions;

final class InvalidApprovalModelException extends ApprovalsException
{
    public static function forClass(string $model, string $expected): self
    {
        return new self(
            "The configured approvals model [{$model}] must be a class extending [{$expected}]."
        );
    }
}
