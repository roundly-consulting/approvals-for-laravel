<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Interfaces\ResolvesExcludedApprovers;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/**
 * Excludes an invoice's author from deciding on it, app-wide.
 */
final class AuthorExclusionResolver implements ResolvesExcludedApprovers
{
    /** @var list<array{0: Model, 1: ApprovalRequest|null}> */
    public static array $calls = [];

    public function excludedApprovers(Model $approvable, ?ApprovalRequest $request): iterable
    {
        self::$calls[] = [$approvable, $request];

        if (! $approvable instanceof InvoiceTestModel || $approvable->author_id === null) {
            return [];
        }

        // A non-model and an unsaved model are skipped, not trusted.
        return ['not a model', new ReviewerTestModel, ReviewerTestModel::query()->findOrFail($approvable->author_id)];
    }
}
