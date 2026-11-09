<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Interfaces;

/**
 * Implemented by a subject (approvable) whose approval should follow one version of it.
 *
 * The fingerprint is stored on a round when it opens and on every decision made on it.
 * When it no longer matches, `Approvals::for($subject)->voidIfChanged()` voids the round,
 * and with `approvals.fingerprint.on_change` set, decisions on it are refused.
 */
interface ProvidesApprovalFingerprint
{
    /**
     * A string that changes whenever the part of the subject the approvers sign off on
     * changes: a version number, an `updated_at` stamp, or a digest of the relevant
     * attributes. At most 255 characters.
     */
    public function approvalFingerprint(): string;
}
