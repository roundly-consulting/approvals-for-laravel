<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Interfaces\ResolvesDelegationScope;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Which delegations count in a place: every one (no resolver, or no round), or only
 * those valid within one scope — the delegations with no scope plus those limited to
 * exactly that scope (none but the unscoped ones when the scope is null).
 *
 * @internal
 */
final readonly class DelegationContext
{
    private const string RESOLVER_KEY = 'approvals.delegations.scope_resolver';

    private function __construct(
        private bool $restricted,
        private ?Model $scope,
    ) {}

    /**
     * Every delegation counts.
     */
    public static function anywhere(): self
    {
        return new self(false, null);
    }

    /**
     * Only delegations valid within `$scope` count (only unscoped ones for null).
     */
    public static function within(?Model $scope): self
    {
        return new self(true, $scope);
    }

    /**
     * The delegations that count for a decision on `$round`: those valid within the scope
     * the configured resolver names for it, or every one without a resolver or a round.
     */
    public static function forRound(?ApprovalRequest $round): self
    {
        if (! $round instanceof ApprovalRequest) {
            return self::anywhere();
        }

        $resolver = self::resolver();

        return $resolver instanceof ResolvesDelegationScope
            ? self::within($resolver->scopeOf($round))
            : self::anywhere();
    }

    /**
     * Limit a delegation query to the delegations that count here.
     *
     * @param  Builder<ApprovalDelegation>  $query
     */
    public function apply(Builder $query): void
    {
        if ($this->restricted) {
            $query->within($this->scope);
        }
    }

    /**
     * The configured resolver, if any. Blank or null is none; anything that does not name
     * a ResolvesDelegationScope implementation throws, naming the key.
     *
     * @throws InvalidConfigurationException
     */
    public static function resolver(): ?ResolvesDelegationScope
    {
        $configured = config(self::RESOLVER_KEY);

        if ($configured === null || (is_string($configured) && trim($configured) === '')) {
            return null;
        }

        if (! is_string($configured) || ! is_subclass_of($configured, ResolvesDelegationScope::class)) {
            throw InvalidConfigurationException::notAnImplementation(self::RESOLVER_KEY, ResolvesDelegationScope::class, $configured);
        }

        $resolver = app($configured);

        if (! $resolver instanceof ResolvesDelegationScope) {
            throw InvalidConfigurationException::notAnImplementation(self::RESOLVER_KEY, ResolvesDelegationScope::class, $configured);
        }

        return $resolver;
    }
}
