<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;

/**
 * A round opened — flat or staged, from `request()` or `workflow()` — fired once per
 * round, after the surrounding transaction commits. A staged round fires it before its
 * first ApprovalStageOpened; advancing a stage and replacing approvers do not fire it.
 *
 * `$approvers` are the models asked to decide now: the round's for a flat round, the
 * first stage's for a staged one (`$stage`), each once. Empty means anyone may decide.
 */
final class ApprovalRoundOpened implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<Model>  $approvers
     */
    public function __construct(
        public ApprovalRequest $round,
        public array $approvers = [],
        public ?ApprovalRequestStage $stage = null,
    ) {}

    /**
     * The model the round asks approval for.
     */
    public function subject(): ?Model
    {
        $subject = $this->round->subject;

        return $subject instanceof Model ? $subject : null;
    }
}
