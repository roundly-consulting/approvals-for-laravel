<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use InvalidArgumentException;
use RoundlyConsulting\Approvals\Models\Approval;

final class ApprovalModelResolver
{
    /**
     * Resolve the configured approval model class.
     *
     * @return class-string<Approval>
     */
    public static function class(): string
    {
        $model = config('approvals.model', Approval::class);

        if (! is_string($model) || ! is_subclass_of($model, Approval::class) && $model !== Approval::class) {
            throw new InvalidArgumentException(
                'The configured approvals.model must be a class extending '.Approval::class.'.'
            );
        }

        return $model;
    }
}
