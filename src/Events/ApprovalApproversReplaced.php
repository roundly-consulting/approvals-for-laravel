<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

/**
 * The approvers of a round were replaced: the open stage's (`$stage` set) through
 * `replaceApprovers()`, or a flat round's (`$stage` null) through `replaceFlatApprovers()`.
 * Fires once the replacement committed, after the retired asks were announced.
 */
final class ApprovalApproversReplaced
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<NamedApprover>  $added  the approvers newly named
     * @param  list<NamedApprover>  $removed  the approvers no longer named
     */
    public function __construct(
        public ApprovalRequest $request,
        public ?ApprovalRequestStage $stage,
        public array $added,
        public array $removed,
    ) {}
}
