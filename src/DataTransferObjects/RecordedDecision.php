<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\DataTransferObjects;

use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;

/**
 * What writing a decision did, so its events can be dispatched once the write has
 * committed: the live decision, the status it moved from, whether anything changed at
 * all (a repeated decision is a no-op), and the earlier decision it superseded, if any.
 *
 * @internal
 */
final readonly class RecordedDecision
{
    public function __construct(
        public Approval $approval,
        public ApprovalStatus $from,
        public bool $changed,
        public ?Approval $superseded = null,
        public ?ApprovalStatus $supersededFrom = null,
    ) {}
}
