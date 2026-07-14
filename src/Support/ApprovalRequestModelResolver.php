<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

final class ApprovalRequestModelResolver
{
    /**
     * Resolve the configured approval request model class.
     *
     * @return class-string<ApprovalRequest>
     */
    public static function class(): string
    {
        $model = ConfiguredApprovalsModel::read('approvals.request_model', ApprovalRequest::class);

        if (! is_a($model, ApprovalRequest::class, true)) {
            throw InvalidApprovalModelException::forClass($model, ApprovalRequest::class);
        }

        return $model;
    }
}
