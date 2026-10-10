<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Testing;

use Closure;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Support\MorphType;

/**
 * A recording, still-performing {@see ApprovalsManager} for host tests, swapped in by
 * `Approvals::fake()`. Every operation still runs against the database; the fake
 * records each one — made through the facade, an injected manager, the builders or
 * the model traits — so a test can assert on what happened.
 */
final class ApprovalsFake extends ApprovalsManager
{
    /** @var list<RecordedApprovalOperation> */
    private array $recorded = [];

    /**
     * @internal
     *
     * @template TAction of object
     * @template TResult
     *
     * @param  class-string<TAction>  $action
     * @param  Closure(TAction): TResult  $execute
     * @param  array<string, mixed>  $context
     * @return TResult
     */
    public function perform(ApprovalOperation $operation, string $action, Closure $execute, array $context = []): mixed
    {
        $result = parent::perform($operation, $action, $execute, $context);

        $this->recorded[] = new RecordedApprovalOperation($operation, $context, $result);

        return $result;
    }

    /**
     * Every recorded operation, optionally only one kind, in call order.
     *
     * @return list<RecordedApprovalOperation>
     */
    public function recorded(?ApprovalOperation $operation = null): array
    {
        if ($operation === null) {
            return $this->recorded;
        }

        return array_values(array_filter(
            $this->recorded,
            static fn (RecordedApprovalOperation $recorded): bool => $recorded->operation === $operation,
        ));
    }

    public function assertApproved(Model $approvable, ?Model $by = null): void
    {
        $this->assertDecision(ApprovalOperation::Approve, 'approved', $approvable, $by);
    }

    public function assertNothingApproved(): void
    {
        $this->assertNone(ApprovalOperation::Approve, 'Expected nothing to be approved, but %d approval(s) were recorded.');
    }

    public function assertRejected(Model $approvable, ?Model $by = null): void
    {
        $this->assertDecision(ApprovalOperation::Reject, 'rejected', $approvable, $by);
    }

    public function assertNothingRejected(): void
    {
        $this->assertNone(ApprovalOperation::Reject, 'Expected nothing to be rejected, but %d rejection(s) were recorded.');
    }

    /**
     * Assert a pending decision was asked for (`->ask()`), optionally from `$actor`.
     */
    public function assertAsked(Model $approvable, ?Model $actor = null): void
    {
        $this->assertDecision(ApprovalOperation::Ask, 'asked for', $approvable, $actor);
    }

    public function assertNothingAsked(): void
    {
        $this->assertNone(ApprovalOperation::Ask, 'Expected no decision to be asked for, but %d were recorded.');
    }

    public function assertCancelled(Model $approvable, ?Model $by = null): void
    {
        $this->assertDecision(ApprovalOperation::Cancel, 'cancelled', $approvable, $by);
    }

    public function assertNothingCancelled(): void
    {
        $this->assertNone(ApprovalOperation::Cancel, 'Expected nothing to be cancelled, but %d cancellation(s) were recorded.');
    }

    public function assertToggled(Model $approvable, ?Model $by = null): void
    {
        $this->assertDecision(ApprovalOperation::Toggle, 'toggled', $approvable, $by);
    }

    public function assertNothingToggled(): void
    {
        $this->assertNone(ApprovalOperation::Toggle, 'Expected nothing to be toggled, but %d toggle(s) were recorded.');
    }

    /**
     * Assert a request was opened for the subject, optionally from the named preset.
     */
    public function assertOpened(Model $subject, ?string $workflow = null): void
    {
        $matches = array_filter(
            $this->recorded(ApprovalOperation::Open),
            static fn (RecordedApprovalOperation $recorded): bool => $recorded->involves('subject', $subject)
                && ($workflow === null || ($recorded->context['workflow'] ?? null) === $workflow),
        );

        Assert::assertNotEmpty(
            $matches,
            $workflow === null
                ? 'Expected an approval request to be opened for the subject, but none was.'
                : "Expected an approval request to be opened for the subject from the [{$workflow}] workflow, but none was.",
        );
    }

    public function assertNothingOpened(): void
    {
        $this->assertNone(ApprovalOperation::Open, 'Expected no approval request to be opened, but %d were recorded.');
    }

    public function assertDelegated(Model $delegator, ?Model $to = null): void
    {
        Assert::assertNotEmpty(
            $this->matching(ApprovalOperation::Delegate, 'delegator', $delegator, 'delegate', $to),
            'Expected approval authority to be delegated, but no matching delegation was recorded.',
        );
    }

    public function assertNothingDelegated(): void
    {
        $this->assertNone(ApprovalOperation::Delegate, 'Expected nothing to be delegated, but %d delegation(s) were recorded.');
    }

    /**
     * Assert the delegator revoked its delegations — only those to `$delegate` when given.
     */
    public function assertRevoked(Model $delegator, ?Model $delegate = null): void
    {
        Assert::assertNotEmpty(
            $this->matching(ApprovalOperation::Revoke, 'delegator', $delegator, 'delegate', $delegate),
            'Expected delegations to be revoked, but no matching revocation was recorded.',
        );
    }

    public function assertNothingRevoked(): void
    {
        $this->assertNone(ApprovalOperation::Revoke, 'Expected nothing to be revoked, but %d revocation(s) were recorded.');
    }

    /**
     * Assert an expiry sweep ran — and, when `$count` is given, that the sweeps lapsed
     * exactly that many decisions and requests in total. With `$subjectType` (a model
     * class or its morph alias) only sweeps scoped to that subject type count.
     */
    public function assertExpired(?int $count = null, ?string $subjectType = null): void
    {
        $sweeps = $this->sweeps($subjectType);

        Assert::assertNotEmpty(
            $sweeps,
            $subjectType === null
                ? 'Expected an expiry sweep to run, but none did.'
                : "Expected an expiry sweep of [{$subjectType}] to run, but none did.",
        );

        if ($count !== null) {
            Assert::assertSame($count, $this->lapsed($sweeps), "Expected {$count} decision(s) to be expired.");
        }
    }

    /**
     * Assert no decision or request was lapsed by an expiry sweep.
     */
    public function assertNothingExpired(): void
    {
        $lapsed = $this->lapsed($this->sweeps());

        Assert::assertSame(0, $lapsed, "Expected nothing to be expired, but {$lapsed} decision(s) were.");
    }

    /**
     * Assert a close of the subject's approval round was requested (`->close()`),
     * optionally with the given outcome.
     */
    public function assertClosed(Model $subject, ?ApprovalStatus $outcome = null): void
    {
        $matches = array_filter(
            $this->recorded(ApprovalOperation::Close),
            static fn (RecordedApprovalOperation $recorded): bool => $recorded->involves('subject', $subject)
                && ($outcome === null || ($recorded->context['outcome'] ?? null) === $outcome),
        );

        Assert::assertNotEmpty(
            $matches,
            $outcome === null
                ? 'Expected an approval round of the subject to be closed, but none was.'
                : "Expected an approval round of the subject to be closed as [{$outcome->value}], but none was.",
        );
    }

    public function assertNothingClosed(): void
    {
        $this->assertNone(ApprovalOperation::Close, 'Expected no approval round to be closed, but %d close(s) were recorded.');
    }

    private function assertDecision(ApprovalOperation $operation, string $verb, Model $approvable, ?Model $actor): void
    {
        Assert::assertNotEmpty(
            $this->matching($operation, 'approvable', $approvable, 'actor', $actor),
            $actor === null
                ? "Expected the model to be {$verb}, but it was not."
                : "Expected the model to be {$verb} by the given actor, but it was not.",
        );
    }

    private function assertNone(ApprovalOperation $operation, string $message): void
    {
        $count = count($this->recorded($operation));

        Assert::assertSame(0, $count, sprintf($message, $count));
    }

    /**
     * @return list<RecordedApprovalOperation>
     */
    private function matching(ApprovalOperation $operation, string $key, Model $model, string $otherKey, ?Model $other): array
    {
        return array_values(array_filter(
            $this->recorded($operation),
            static fn (RecordedApprovalOperation $recorded): bool => $recorded->involves($key, $model)
                && ($other === null || $recorded->involves($otherKey, $other)),
        ));
    }

    /**
     * The recorded expiry sweeps — only those scoped to `$subjectType` when given.
     *
     * @return list<RecordedApprovalOperation>
     */
    private function sweeps(?string $subjectType = null): array
    {
        $sweeps = $this->recorded(ApprovalOperation::Expire);

        if ($subjectType === null) {
            return $sweeps;
        }

        $type = MorphType::of($subjectType);

        return array_values(array_filter(
            $sweeps,
            static fn (RecordedApprovalOperation $sweep): bool => is_string($recorded = $sweep->context['subjectType'] ?? null)
                && MorphType::of($recorded) === $type,
        ));
    }

    /**
     * @param  list<RecordedApprovalOperation>  $sweeps
     */
    private function lapsed(array $sweeps): int
    {
        $total = 0;

        foreach ($sweeps as $sweep) {
            $total += is_int($sweep->result) ? $sweep->result : 0;
        }

        return $total;
    }
}
