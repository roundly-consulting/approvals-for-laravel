<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Interfaces\ProvidesApprovalFingerprint;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Reads a subject's ProvidesApprovalFingerprint value and compares it with the one a
 * round stored when it opened.
 *
 * @internal
 */
final class SubjectFingerprint
{
    public const string IGNORE = 'ignore';

    public const string REFUSE = 'refuse';

    public const string VOID = 'void';

    private const int MAX_LENGTH = 255;

    /**
     * The fingerprint of the subject as it is stored now — read from a fresh copy, so a
     * stale model in memory never vouches for a version that has since changed. Null when
     * the subject provides none.
     *
     * @throws InvalidApprovalRequestException when the fingerprint is longer than 255 characters
     */
    public static function current(Model $subject): ?string
    {
        if (! $subject instanceof ProvidesApprovalFingerprint) {
            return null;
        }

        $fresh = $subject->exists ? $subject->fresh() : null;
        $source = $fresh instanceof ProvidesApprovalFingerprint ? $fresh : $subject;

        $fingerprint = $source->approvalFingerprint();

        if (strlen($fingerprint) > self::MAX_LENGTH) {
            throw InvalidApprovalRequestException::fingerprintTooLong($subject, self::MAX_LENGTH);
        }

        return $fingerprint;
    }

    /**
     * Whether the subject changed since the round opened: the round stored a fingerprint
     * and the subject's current one differs. A round opened without one (or a subject
     * that no longer provides one) has nothing to compare, so it never reads as changed.
     */
    public static function changed(ApprovalRequest $request, ?string $current): bool
    {
        $openedWith = $request->openedWithFingerprint();

        return $openedWith !== null
            && $current !== null
            && ! hash_equals($openedWith, $current);
    }

    /**
     * What a decision on a changed subject does (`approvals.fingerprint.on_change`):
     * `ignore` (the default) records it, `refuse` refuses it, `void` refuses it and voids
     * the round.
     *
     * @throws InvalidConfigurationException
     */
    public static function onChange(): string
    {
        return Config::oneOf('approvals.fingerprint.on_change', [self::IGNORE, self::REFUSE, self::VOID], self::IGNORE);
    }
}
