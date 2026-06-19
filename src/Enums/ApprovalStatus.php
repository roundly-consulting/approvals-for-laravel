<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Enums;

enum ApprovalStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * A decision has been made (approved or rejected).
     */
    public function isDecided(): bool
    {
        return $this === self::Approved || $this === self::Rejected;
    }

    /**
     * A terminal state that can no longer transition.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /**
     * Whether a transition from this status to the given status is legal.
     *
     * A pending approval may move to any decided/withdrawn/expired state. An approved
     * approval may still be cancelled (withdrawn). Rejected, cancelled and expired are
     * terminal.
     */
    public function canTransitionTo(self $to): bool
    {
        return match ($this) {
            self::Pending => $to !== self::Pending,
            self::Approved => $to === self::Cancelled,
            self::Rejected, self::Cancelled, self::Expired => false,
        };
    }
}
