<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\Interfaces\ResolvesExcludedApprovers;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Who may not decide on an approvable: the actors the round's opener excluded
 * (`->excluding()`), plus those the configured `approvals.exclusions.resolver` names.
 *
 * @internal
 */
final class ExcludedApprovers
{
    private const string RESOLVER_KEY = 'approvals.exclusions.resolver';

    /**
     * Every actor excluded from deciding on `$approvable` within `$request` (or on a
     * standalone decision when it is null).
     *
     * @return list<NamedApprover>
     */
    public function for(Model $approvable, ?ApprovalRequest $request): array
    {
        $excluded = $request instanceof ApprovalRequest ? $request->excludedApprovers() : [];

        $resolver = self::resolver();

        if (! $resolver instanceof ResolvesExcludedApprovers) {
            return $excluded;
        }

        foreach ($resolver->excludedApprovers($approvable, $request) as $actor) {
            $identified = ApproverList::identify($actor);

            if ($identified instanceof NamedApprover) {
                $excluded[] = $identified;
            }
        }

        return $excluded;
    }

    /**
     * The configured resolver, if any. Blank or null is none; anything that does not name
     * a ResolvesExcludedApprovers implementation throws, naming the key.
     *
     * @throws InvalidConfigurationException
     */
    public static function resolver(): ?ResolvesExcludedApprovers
    {
        $configured = config(self::RESOLVER_KEY);

        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return null;
        }

        if (! is_string($configured) || ! is_subclass_of($configured, ResolvesExcludedApprovers::class)) {
            throw InvalidConfigurationException::notAnImplementation(self::RESOLVER_KEY, ResolvesExcludedApprovers::class, $configured);
        }

        $resolver = app($configured);

        if (! $resolver instanceof ResolvesExcludedApprovers) {
            throw InvalidConfigurationException::notAnImplementation(self::RESOLVER_KEY, ResolvesExcludedApprovers::class, $configured);
        }

        return $resolver;
    }
}
