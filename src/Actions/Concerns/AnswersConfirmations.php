<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ConfirmationRefusal;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

/**
 * The guards shared by confirming and declining a confirmation.
 *
 * @internal
 */
trait AnswersConfirmations
{
    /**
     * Refuse an answer the confirmation cannot take: one from a model other than its
     * person (checked first, so a stranger learns nothing else about it), or one to a
     * confirmation already used, declined or past its expiry.
     *
     * @throws ConfirmationRefusedException
     */
    private function refuseUnanswerable(ApprovalConfirmation $confirmation, Model $person, CarbonInterface $now): void
    {
        if (! $confirmation->isAddressedTo($person)) {
            throw ConfirmationRefusedException::because(ConfirmationRefusal::NotThePerson, $confirmation);
        }

        $this->refuseClosed($confirmation, $now);
    }

    /**
     * Move the confirmation to `$outcome`, or refuse when a concurrent answer (or the
     * sweep) moved it first. The claim is one conditional update, so exactly one of two
     * racing answers wins. It runs in a transaction of its own that is retried on a
     * serialization failure, so under repeatable read the loser re-reads the winner's
     * row and is refused as used instead of failing with a database error.
     *
     * @throws ConfirmationRefusedException
     */
    private function claimOrRefuse(ApprovalConfirmation $confirmation, ConfirmationStatus $outcome, ?string $reason, CarbonInterface $now): void
    {
        $claimed = $confirmation->getConnection()->transaction(
            static fn (): bool => $confirmation->claim($outcome, $reason, $now),
            3,
        );

        if ($claimed) {
            return;
        }

        // The claim re-read the row: whatever moved it first says why this answer lost.
        // A row the claim did not move can only be closed — `used` is the safe fallback.
        throw ConfirmationRefusedException::because($this->closedRefusal($confirmation, $now) ?? ConfirmationRefusal::Used, $confirmation);
    }

    /**
     * @throws ConfirmationRefusedException when the confirmation is used, declined or expired
     */
    private function refuseClosed(ApprovalConfirmation $confirmation, CarbonInterface $now): void
    {
        $refusal = $this->closedRefusal($confirmation, $now);

        if ($refusal !== null) {
            throw ConfirmationRefusedException::because($refusal, $confirmation);
        }
    }

    /**
     * Why the confirmation takes no answer, or null while it is open. An overdue one
     * nobody swept yet lapses here, so ApprovalConfirmationExpired fires once.
     */
    private function closedRefusal(ApprovalConfirmation $confirmation, CarbonInterface $now): ?ConfirmationRefusal
    {
        $refusal = match ($confirmation->status) {
            ConfirmationStatus::Confirmed => ConfirmationRefusal::Used,
            ConfirmationStatus::Declined => ConfirmationRefusal::Declined,
            ConfirmationStatus::Expired => ConfirmationRefusal::Expired,
            ConfirmationStatus::Pending => $confirmation->isOverdue($now) ? ConfirmationRefusal::Expired : null,
        };

        if ($refusal === ConfirmationRefusal::Expired) {
            $confirmation->lapseIfOverdue($now);
        }

        return $refusal;
    }
}
