<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\Concerns\ResolvesApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalRequestVoided;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\SubjectFingerprint;

/**
 * Voids the subject's open rounds whose subject changed since they opened — the
 * fingerprint the round stored (ProvidesApprovalFingerprint) no longer matches the
 * subject's current one. A voided round closes as cancelled the way close() closes one:
 * its outstanding asks retired, ApprovalRequestResolved and ApprovalStatusChanged, then
 * ApprovalRequestVoided.
 *
 * A round without a stored fingerprint, a subject that provides none, a round whose
 * fingerprint still matches and a closed round are left alone. A round past its expiry
 * lapses as expired instead.
 *
 * The comparison and the status write run under the round's row lock, the one every
 * decision takes before it writes, so a decision never lands on a round as it is voided.
 */
final class VoidChangedApprovalRequestsAction
{
    use ResolvesApproval;

    /**
     * Void the subject's changed open rounds — only `$request` when given, which must
     * belong to the subject.
     *
     * @return int the number of rounds this call voided
     *
     * @throws InvalidApprovalRequestException when `$request` belongs to another subject
     */
    public function execute(Model $subject, ?ApprovalRequest $request = null): int
    {
        if ($request instanceof ApprovalRequest) {
            $this->ensureRequestBelongsTo($request, $subject);
        }

        $current = SubjectFingerprint::current($subject);

        if ($current === null) {
            return 0;
        }

        $voided = 0;

        foreach ($this->roundsOf($subject, $request) as $round) {
            if ($this->voidIfChanged($round, $current)) {
                $voided++;
            }
        }

        return $voided;
    }

    /**
     * @return iterable<ApprovalRequest>
     */
    private function roundsOf(Model $subject, ?ApprovalRequest $request): iterable
    {
        if ($request instanceof ApprovalRequest) {
            return [$request];
        }

        $model = ApprovalRequestModelResolver::class();

        return $model::query()
            ->whereMorphedTo('subject', $subject)
            ->where('status', ApprovalStatus::Pending)
            ->orderBy('id')
            ->get();
    }

    private function voidIfChanged(ApprovalRequest $round, string $current): bool
    {
        $round->refresh();

        if ($round->lapseIfOverdue()) {
            return false;
        }

        $openedWith = null;

        $voided = $round->getConnection()->transaction(function () use ($round, $current, &$openedWith): ?ApprovalRequest {
            $locked = $round->newQuery()->whereKey($round->getKey())->lockForUpdate()->first();

            if (! $locked instanceof ApprovalRequest
                || $locked->status !== ApprovalStatus::Pending
                || ! SubjectFingerprint::changed($locked, $current)) {
                return null;
            }

            $openedWith = $locked->openedWithFingerprint();

            return $locked->claimFinal(ApprovalStatus::Cancelled) ? $locked : null;
        });

        if (! $voided instanceof ApprovalRequest || $openedWith === null) {
            return false;
        }

        $voided->announceFinal();

        ApprovalRequestVoided::dispatch($voided, $openedWith, $current);

        $round->refresh();

        return true;
    }
}
