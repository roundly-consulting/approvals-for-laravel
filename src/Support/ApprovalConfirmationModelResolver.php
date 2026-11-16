<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

final class ApprovalConfirmationModelResolver
{
    /**
     * Resolve the configured confirmation model class.
     *
     * @return class-string<ApprovalConfirmation>
     */
    public static function class(): string
    {
        $model = ConfiguredApprovalsModel::read('approvals.confirmation_model', ApprovalConfirmation::class);

        if (! is_a($model, ApprovalConfirmation::class, true)) {
            throw InvalidApprovalModelException::forClass($model, ApprovalConfirmation::class);
        }

        return $model;
    }
}
