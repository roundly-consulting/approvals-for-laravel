<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AnswersConfirmations;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationDeclined;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

final class DeclineAction
{
    use AnswersConfirmations;

    /**
     * Decline the confirmation as `$person`, with an optional reason. Only the named
     * person can decline it, and only while it is pending and unexpired; declining uses
     * it up, atomically, exactly as a confirm does — so a decline and a confirm racing
     * each other cannot both succeed.
     *
     * @return ApprovalConfirmation the confirmation, read back (the instance passed in, refreshed)
     *
     * @throws ConfirmationRefusedException `not_the_person`, `used`, `declined` or `expired`
     */
    public function execute(ApprovalConfirmation $confirmation, Model $person, ?string $reason = null): ApprovalConfirmation
    {
        $now = CarbonImmutable::now();

        $confirmation->refresh();

        $this->refuseUnanswerable($confirmation, $person, $now);

        $this->claimOrRefuse($confirmation, ConfirmationStatus::Declined, $reason, $now);

        ApprovalConfirmationDeclined::dispatch($confirmation, $person);

        return $confirmation;
    }
}
