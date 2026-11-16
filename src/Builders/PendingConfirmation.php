<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateInterval;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\RequestConfirmationAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Exceptions\InvalidConfirmationException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;

/**
 * A single-use confirmation about to be asked for:
 * `Approvals::confirmation($toolCall)->payload($hash)->by($user)->expiresIn(...)->request()`.
 */
final class PendingConfirmation
{
    private ?string $payloadHash = null;

    private ?Model $person = null;

    private ?CarbonInterface $expiresAt = null;

    public function __construct(
        private readonly ApprovalsManager $manager,
        private readonly Model $subject,
    ) {}

    /**
     * The hash of the exact action to confirm — the tool and its arguments, say. Only a
     * confirm with the same hash succeeds; compute it the same way on both sides.
     */
    public function payload(string $hash): self
    {
        $this->payloadHash = $hash;

        return $this;
    }

    /**
     * The one person who may confirm (or decline) it. Nobody else can — not a delegate,
     * not an approver of the subject.
     */
    public function by(Model $person): self
    {
        $this->person = $person;

        return $this;
    }

    /**
     * Mark the confirmation as single use. Every confirmation is single use — the first
     * confirm or decline uses it up — so this changes nothing; it is there so the call
     * site says so.
     */
    public function singleUse(): self
    {
        return $this;
    }

    /**
     * The confirmation expires this long from now: an interval (`CarbonInterval::minutes(5)`)
     * or a number of seconds. Without it, `approvals.confirmations.expiry` applies.
     */
    public function expiresIn(DateInterval|int $interval): self
    {
        $now = CarbonImmutable::now();

        $this->expiresAt = is_int($interval) ? $now->addSeconds($interval) : $now->add($interval);

        return $this;
    }

    /**
     * The confirmation expires at this moment. Without it,
     * `approvals.confirmations.expiry` applies.
     */
    public function expiringAt(CarbonInterface $at): self
    {
        $this->expiresAt = $at;

        return $this;
    }

    /**
     * Ask for the confirmation: it is stored pending and ApprovalConfirmationRequested fires.
     *
     * @throws InvalidConfirmationException when by() or payload() is missing, the subject or person is unsaved, the hash is blank or over 255 characters, or the expiry is not in the future
     */
    public function request(): ApprovalConfirmation
    {
        $person = $this->person ?? throw InvalidConfirmationException::missingPerson();
        $hash = $this->payloadHash ?? throw InvalidConfirmationException::missingPayload();

        return $this->manager->perform(
            ApprovalOperation::RequestConfirmation,
            RequestConfirmationAction::class,
            fn (RequestConfirmationAction $action): ApprovalConfirmation => $action->execute($this->subject, $person, $hash, $this->expiresAt),
            ['subject' => $this->subject, 'person' => $person, 'expires_at' => $this->expiresAt],
        );
    }
}
