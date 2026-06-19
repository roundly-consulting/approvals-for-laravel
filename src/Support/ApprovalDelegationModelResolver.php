<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;

final class ApprovalDelegationModelResolver
{
    /**
     * Resolve the configured approval delegation model class.
     *
     * @return class-string<ApprovalDelegation>
     */
    public static function class(): string
    {
        $model = config('approvals.delegation_model', ApprovalDelegation::class);

        if (! is_string($model) || ($model !== ApprovalDelegation::class && ! is_subclass_of($model, ApprovalDelegation::class))) {
            throw InvalidApprovalModelException::forClass(
                is_string($model) ? $model : get_debug_type($model),
                ApprovalDelegation::class,
            );
        }

        return $model;
    }
}
