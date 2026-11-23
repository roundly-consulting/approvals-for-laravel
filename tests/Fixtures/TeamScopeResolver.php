<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Interfaces\ResolvesDelegationScope;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Places each round in the team a test assigned to its subject (`TeamScopeResolver::$teams`,
 * keyed by subject morph class and key); a subject without one belongs to no team.
 */
final class TeamScopeResolver implements ResolvesDelegationScope
{
    /** @var array<string, Model> */
    public static array $teams = [];

    public static function place(Model $subject, Model $team): void
    {
        self::$teams[$subject->getMorphClass().':'.$subject->getKey()] = $team;
    }

    public function scopeOf(ApprovalRequest $round): ?Model
    {
        return self::$teams[$round->subject_type.':'.$round->subject_id] ?? null;
    }
}
