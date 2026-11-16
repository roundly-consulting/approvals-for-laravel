<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\Approvals\Support\ApprovalConfirmationModelResolver;
use RoundlyConsulting\Approvals\Support\MorphType;

final class ExpireConfirmationsAction
{
    /**
     * Lapse every pending confirmation whose expiry is at or before the moment (now when
     * omitted), firing ApprovalConfirmationExpired for each — only those about one subject
     * type (a model class or its morph alias) when given. A confirmation answered while
     * the sweep runs is left as answered.
     *
     * @return int the number of confirmations expired
     */
    public function execute(?CarbonInterface $now = null, ?string $subjectType = null): int
    {
        $now = CarbonImmutable::instance(($now ?? CarbonImmutable::now())->toDateTimeImmutable());

        // Rows store the morph alias when the host maps one: a class-string must match it.
        $subjectType = $subjectType === null ? null : MorphType::of($subjectType);

        $model = ApprovalConfirmationModelResolver::class();

        $count = 0;

        $overdue = $model::query()
            ->overdue($now)
            ->when($subjectType !== null, static fn (Builder $query): Builder => $query->where('subject_type', $subjectType))
            ->lazyById(200);

        foreach ($overdue as $confirmation) {
            if ($confirmation->lapseIfOverdue($now)) {
                $count++;
            }
        }

        return $count;
    }
}
