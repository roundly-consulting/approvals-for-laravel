<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Builders;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\DelegateApprovalsAction;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;

/**
 * Fluent builder for delegating approval authority. The delegation row is created
 * immediately (open-ended) when the builder is constructed, so a bare
 * `delegateApprovalsTo($x)` works; chaining `->from()`/`->until()`/`->for()` narrows
 * the window in place.
 */
final class PendingDelegation
{
    private readonly ApprovalDelegation $delegation;

    public function __construct(Model $delegator, Model $delegate)
    {
        $this->delegation = app(DelegateApprovalsAction::class)->execute($delegator, $delegate);
    }

    public function from(CarbonInterface $when): self
    {
        $this->delegation->starts_at = CarbonImmutable::instance($when->toDateTimeImmutable());
        $this->delegation->save();

        return $this;
    }

    public function until(CarbonInterface $when): self
    {
        $this->delegation->ends_at = CarbonImmutable::instance($when->toDateTimeImmutable());
        $this->delegation->save();

        return $this;
    }

    public function for(int $seconds): self
    {
        $this->delegation->ends_at = CarbonImmutable::now()->addSeconds($seconds);
        $this->delegation->save();

        return $this;
    }

    public function save(): ApprovalDelegation
    {
        return $this->delegation;
    }

    public function delegation(): ApprovalDelegation
    {
        return $this->delegation;
    }
}
