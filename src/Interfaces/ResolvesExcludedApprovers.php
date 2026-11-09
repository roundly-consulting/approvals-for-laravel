<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * Names the actors who may never decide on an approvable, app-wide: its author, say.
 * Point `approvals.exclusions.resolver` at an implementation; it is resolved from the
 * container.
 *
 * An excluded actor can neither decide nor be asked, cannot act as anyone's delegate,
 * and no delegate can decide on its behalf. It cannot be named as an approver either.
 */
interface ResolvesExcludedApprovers
{
    /**
     * The actors excluded from deciding on `$approvable` — within `$request` when the
     * decision belongs to a round (null for a standalone decision, and when a round is
     * being opened).
     *
     * @return iterable<Model>
     */
    public function excludedApprovers(Model $approvable, ?ApprovalRequest $request): iterable;
}
