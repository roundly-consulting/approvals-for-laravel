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
        $model = ConfiguredApprovalsModel::read('approvals.delegation_model', ApprovalDelegation::class);

        if (! is_a($model, ApprovalDelegation::class, true)) {
            throw InvalidApprovalModelException::forClass($model, ApprovalDelegation::class);
        }

        return $model;
    }
}
