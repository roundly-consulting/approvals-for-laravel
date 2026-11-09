<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;

/**
 * A round was voided (closed as cancelled) because its subject changed since the round
 * opened: its ProvidesApprovalFingerprint value no longer matches the one stored.
 * Fires after ApprovalRequestResolved and ApprovalStatusChanged.
 */
final class ApprovalRequestVoided
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  string  $openedWith  the fingerprint stored when the round opened
     * @param  string  $current  the subject's fingerprint that no longer matched it
     */
    public function __construct(
        public ApprovalRequest $request,
        public string $openedWith,
        public string $current,
    ) {}
}
