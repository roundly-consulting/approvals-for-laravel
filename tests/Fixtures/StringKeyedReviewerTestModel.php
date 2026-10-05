<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\Approvals\Traits\GivesApprovals;

/**
 * An approver keyed the way a uuid/ulid host keys its models: its id follows
 * `approvals.key_type`.
 */
class StringKeyedReviewerTestModel extends Model
{
    use GivesApprovals;
    use HasUniqueStringIds;

    public $table = 'string_keyed_reviewers';

    protected $guarded = [];

    public $timestamps = false;

    public function newUniqueId(): string
    {
        return config('approvals.key_type') === 'ulid' ? (string) Str::ulid() : (string) Str::uuid();
    }

    protected function isValidUniqueId(mixed $value): bool
    {
        return Str::isUuid($value) || Str::isUlid($value);
    }
}
