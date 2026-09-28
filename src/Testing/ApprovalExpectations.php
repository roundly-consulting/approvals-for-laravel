<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Testing;

use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

/**
 * Registers Pest custom expectations for approvable models. Call once from the host
 * app's tests/Pest.php:
 *
 *     RoundlyConsulting\Approvals\Testing\ApprovalExpectations::register();
 *
 * This deliberately resolves Pest's expect() at call time via the global helper, so the
 * package keeps no runtime dependency on Pest.
 */
final class ApprovalExpectations
{
    public static function register(): void
    {
        if (! function_exists('expect')) {
            return;
        }

        expect()->extend('toBeApproved', function () {
            /** @var Model $model */
            $model = $this->value;

            Assert::assertTrue(
                ApprovalExpectations::statusOf($model) === ApprovalStatus::Approved,
                'Expected the model to be approved.',
            );

            return $this;
        });

        expect()->extend('toBePendingApproval', function () {
            /** @var Model $model */
            $model = $this->value;

            Assert::assertTrue(
                ApprovalExpectations::statusOf($model) === ApprovalStatus::Pending,
                'Expected the model to be pending approval.',
            );

            return $this;
        });

        expect()->extend('toBeRejected', function () {
            /** @var Model $model */
            $model = $this->value;

            Assert::assertTrue(
                ApprovalExpectations::statusOf($model) === ApprovalStatus::Rejected,
                'Expected the model to be rejected.',
            );

            return $this;
        });
    }

    /**
     * Resolve a model's effective approval status: its latest request's status when it
     * has one, otherwise derived from the decisions recorded against it.
     */
    public static function statusOf(Model $model): ApprovalStatus
    {
        if (method_exists($model, 'currentApprovalStatus')) {
            /** @var ApprovalStatus $status */
            $status = $model->currentApprovalStatus();

            return $status;
        }

        $approvalModel = ApprovalModelResolver::class();

        $rejected = $approvalModel::query()
            ->whereMorphedTo('approvable', $model)
            ->where('status', ApprovalStatus::Rejected)
            ->inForce()
            ->exists();

        if ($rejected) {
            return ApprovalStatus::Rejected;
        }

        $approved = $approvalModel::query()
            ->whereMorphedTo('approvable', $model)
            ->where('status', ApprovalStatus::Approved)
            ->inForce()
            ->exists();

        return $approved ? ApprovalStatus::Approved : ApprovalStatus::Pending;
    }
}
