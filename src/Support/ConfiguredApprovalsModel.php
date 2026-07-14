<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * Reads one of the package's configurable models through the toolkit's
 * {@see ModelResolver}, which validates that the configured value is a real
 * Eloquent model class.
 *
 * The toolkit signals a bad value with its own InvalidConfigurationException;
 * this package documents (and tests) that a bad model config raises an
 * InvalidApprovalModelException, so that contract is preserved here. Callers
 * narrow the validated class-string to the package base class they expect.
 *
 * @internal
 */
final class ConfiguredApprovalsModel
{
    /**
     * The validated Eloquent model class configured at `$key`.
     *
     * @param  class-string<Model>  $expected
     * @return class-string<Model>
     */
    public static function read(string $key, string $expected): string
    {
        $configured = config($key, $expected);

        // The toolkit treats an explicitly null value as an absent key and falls
        // back; this package rejects it, as it always has.
        if (! is_string($configured)) {
            throw InvalidApprovalModelException::forClass(get_debug_type($configured), $expected);
        }

        try {
            return ModelResolver::for($key, $expected);
        } catch (InvalidConfigurationException) {
            throw InvalidApprovalModelException::forClass($configured, $expected);
        }
    }
}
