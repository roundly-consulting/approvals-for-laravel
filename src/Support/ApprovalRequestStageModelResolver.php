<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

final class ApprovalRequestStageModelResolver
{
    /**
     * Resolve the configured approval request stage model class.
     *
     * @return class-string<ApprovalRequestStage>
     */
    public static function class(): string
    {
        $model = config('approvals.stage_model', ApprovalRequestStage::class);

        if (! is_string($model) || ($model !== ApprovalRequestStage::class && ! is_subclass_of($model, ApprovalRequestStage::class))) {
            throw InvalidApprovalModelException::forClass(
                is_string($model) ? $model : get_debug_type($model),
                ApprovalRequestStage::class,
            );
        }

        return $model;
    }
}
