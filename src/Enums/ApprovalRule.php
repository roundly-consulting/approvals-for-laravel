<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Enums;

enum ApprovalRule: string
{
    /** Every approver must approve. */
    case Unanimous = 'unanimous';

    /** A configurable number of approvals is required. */
    case Quorum = 'quorum';

    /** A single approval resolves the request. */
    case Any = 'any';
}
