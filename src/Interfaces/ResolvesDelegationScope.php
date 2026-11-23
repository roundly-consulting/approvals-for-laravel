<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Names the context (a team, a tenant) an approval round belongs to, so a delegation
 * limited to one context (`->within($team)`) counts only there. Point
 * `approvals.delegations.scope_resolver` at an implementation; it is resolved from the
 * container.
 *
 * With a resolver configured, a round counts the delegations with no scope plus those
 * limited to the scope it returns. A round it returns null for counts only the
 * delegations with no scope. Without a resolver every delegation counts in every round.
 */
interface ResolvesDelegationScope
{
    /**
     * The context `$round` belongs to, or null when it belongs to none.
     */
    public function scopeOf(ApprovalRequest $round): ?Model;
}
