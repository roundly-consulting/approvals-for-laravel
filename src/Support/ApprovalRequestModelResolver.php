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
        $model = config('approvals.request_model', ApprovalRequest::class);

        if (! is_string($model) || ($model !== ApprovalRequest::class && ! is_subclass_of($model, ApprovalRequest::class))) {
            throw InvalidApprovalModelException::forClass(
                is_string($model) ? $model : get_debug_type($model),
                ApprovalRequest::class,
            );
        }

        return $model;
    }
}
