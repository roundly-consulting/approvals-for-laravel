<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Enums\ConfirmationStatus;
use RoundlyConsulting\Approvals\Events\ApprovalConfirmationRequested;
use RoundlyConsulting\Approvals\Exceptions\InvalidConfirmationException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;
use RoundlyConsulting\Approvals\Support\ApprovalConfirmationModelResolver;
use RoundlyConsulting\Approvals\Support\ConfirmationLifetime;

final class RequestConfirmationAction
{
    /**
     * Ask `$person` to confirm the action on `$subject` whose payload hash is
     * `$payloadHash`, once, before `$expiresAt` — or, when it is null, before
     * `approvals.confirmations.expiry` seconds from now (never, when that is not set).
     *
     * @throws InvalidConfirmationException for an unsaved subject or person, a blank or over-long hash, or an expiry not in the future
     */
    public function execute(Model $subject, Model $person, string $payloadHash, ?CarbonInterface $expiresAt = null): ApprovalConfirmation
    {
        if (! $subject->exists) {
            throw InvalidConfirmationException::unsaved('subject');
        }

        if (! $person->exists) {
            throw InvalidConfirmationException::unsaved('person');
        }

        if (trim($payloadHash) === '' || strlen($payloadHash) > 255) {
            throw InvalidConfirmationException::invalidPayload();
        }

        $now = CarbonImmutable::now();

        if ($expiresAt === null && ($lifetime = ConfirmationLifetime::seconds()) !== null) {
            $expiresAt = $now->addSeconds($lifetime);
        }

        if ($expiresAt !== null && $expiresAt->lessThanOrEqualTo($now)) {
            throw InvalidConfirmationException::expiryNotInFuture();
        }

        $model = ApprovalConfirmationModelResolver::class();

        $confirmation = $model::query()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'person_type' => $person->getMorphClass(),
            'person_id' => $person->getKey(),
            'payload_hash' => $payloadHash,
            'status' => ConfirmationStatus::Pending,
            'expires_at' => $expiresAt,
        ]);

        ApprovalConfirmationRequested::dispatch($confirmation);

        return $confirmation;
    }
}
