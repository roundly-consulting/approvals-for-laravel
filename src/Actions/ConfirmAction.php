<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\AnswersConfirmations;
use RoundlyConsulting\Approvals\Enums\ConfirmationRefusal;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationConfirmed;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

final class ConfirmAction
{
    use AnswersConfirmations;

    /**
     * Confirm the confirmation as `$person` for the action whose payload hash is `$hash`.
     * It succeeds only for the named person, a matching hash (compared in constant time)
     * and a confirmation still pending and unexpired, and marks it used atomically: of
     * two concurrent confirms exactly one succeeds. A refusal records nothing, except
     * that an overdue confirmation lapses (ApprovalConfirmationExpired). A mismatching
     * hash leaves the confirmation pending.
     *
     * @return ApprovalConfirmation the confirmation, read back (the instance passed in, refreshed)
     *
     * @throws ConfirmationRefusedException `not_the_person`, `used`, `declined`, `expired` or `mismatch`
     */
    public function execute(ApprovalConfirmation $confirmation, Model $person, string $hash): ApprovalConfirmation
    {
        $now = CarbonImmutable::now();

        $confirmation->refresh();

        $this->refuseUnanswerable($confirmation, $person, $now);

        if (! $confirmation->matchesPayload($hash)) {
            throw ConfirmationRefusedException::because(ConfirmationRefusal::Mismatch, $confirmation);
        }

        $this->claimOrRefuse($confirmation, ConfirmationStatus::Confirmed, null, $now);

        ApprovalConfirmationConfirmed::dispatch($confirmation, $person);

        return $confirmation;
    }
}
