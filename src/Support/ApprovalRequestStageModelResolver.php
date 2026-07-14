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
        $model = ConfiguredApprovalsModel::read('approvals.stage_model', ApprovalRequestStage::class);

        if (! is_a($model, ApprovalRequestStage::class, true)) {
            throw InvalidApprovalModelException::forClass($model, ApprovalRequestStage::class);
        }

        return $model;
    }
}
