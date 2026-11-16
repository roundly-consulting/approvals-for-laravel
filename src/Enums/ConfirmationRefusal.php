<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Enums;

use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Enums\Helpers;

/**
 * Why a confirmation was refused. The value is the stable error code a host returns to
 * its client ({@see ConfirmationRefusedException::errorCode()}); message() is the
 * translated text (`approvals::confirmations.refused.<code>`).
 */
enum ConfirmationRefusal: string
{
    use Helpers;

    /** The payload hash given does not match the one the confirmation was asked for. */
    case Mismatch = 'mismatch';

    /** The confirmation was already confirmed: it is single use. */
    case Used = 'used';

    /** The confirmation is past its expiry. */
    case Expired = 'expired';

    /** The model answering is not the person the confirmation was asked of. */
    case NotThePerson = 'not_the_person';

    /** The person already declined the confirmation. */
    case Declined = 'declined';

    /**
     * The refusal's translated message, in the current locale.
     */
    public function message(): string
    {
        $message = __('approvals::confirmations.refused.'.$this->value);

        return is_string($message) ? $message : $this->value;
    }
}
