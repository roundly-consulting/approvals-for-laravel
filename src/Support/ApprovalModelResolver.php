<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
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
        $model = ConfiguredApprovalsModel::read('approvals.model', Approval::class);

        if (! is_a($model, Approval::class, true)) {
            throw InvalidApprovalModelException::forClass($model, Approval::class);
        }

        return $model;
    }
}
