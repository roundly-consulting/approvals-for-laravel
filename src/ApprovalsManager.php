<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\ConfirmAction;
use RoundlyConsulting\Approvals\Actions\DeclineAction;
use RoundlyConsulting\Approvals\Actions\ExpireApprovalsAction;
use RoundlyConsulting\Approvals\Actions\ExpireConfirmationsAction;
use RoundlyConsulting\Approvals\Builders\DelegationsHandle;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Builders\PendingApprovalRequest;
use RoundlyConsulting\Approvals\Builders\PendingConfirmation;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalProgress;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\ConfirmationRefusedException;
use RoundlyConsulting\Approvals\Models\ApprovalConfirmation;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalConfirmationModelResolver;
use RoundlyConsulting\Approvals\Support\DelegationResolver;
use RoundlyConsulting\Approvals\Support\WorkflowResolver;

/**
 * The approvals API: the root behind the {@see Facades\Approvals} facade, and the
 * class to inject when you prefer dependency injection.
 *
 * Not final on purpose: {@see Testing\ApprovalsFake} extends it so a constructor-
 * injected manager receives the fake under `Approvals::fake()`.
 */
class ApprovalsManager
{
    public function __construct(
        protected readonly Container $container,
    ) {}

    /**
     * Begin a decision on the given approvable.
     */
    public function for(Model $approvable): PendingApproval
    {
        return (new PendingApproval($this))->for($approvable);
    }

    /**
     * Begin a decision made by the given actor.
     */
    public function as(Model $actor): PendingApproval
    {
        return (new PendingApproval($this))->as($actor);
    }

    /**
     * Begin opening a multi-approver request for the subject: flat (`from()`), staged
     * (`stages()`) or from a named workflow preset (`workflow()`).
     */
    public function request(Model $subject): PendingApprovalRequest
    {
        return new PendingApprovalRequest($this, $subject);
    }

    /**
     * Resolve a named workflow preset from `config('approvals.workflows')`.
     */
    public function preset(string $name): WorkflowPreset
    {
        return $this->container->make(WorkflowResolver::class)->resolve($name);
    }

    /**
     * The status of the subject's latest request (`Pending` when it has none).
     */
    public function status(Model $subject): ApprovalStatus
    {
        return ApprovalChecker::status($subject);
    }

    /**
     * Whether the subject changed since its open round opened (its
     * ProvidesApprovalFingerprint value no longer matches the round's). Void such rounds
     * with `for($subject)->voidIfChanged()`.
     */
    public function subjectChanged(Model $subject): bool
    {
        return ApprovalChecker::subjectChanged($subject);
    }

    /**
     * How far the subject's latest request has progressed, if it has one.
     */
    public function progress(Model $subject): ?ApprovalProgress
    {
        return ApprovalChecker::progress($subject);
    }

    /**
     * The stage open for decisions on the subject's latest staged request, if any.
     */
    public function currentStage(Model $subject): ?ApprovalRequestStage
    {
        return ApprovalChecker::currentStage($subject);
    }

    /**
     * The delegations the given approver hands out: grant, revoke and list them.
     */
    public function delegations(Model $delegator): DelegationsHandle
    {
        return new DelegationsHandle($this, $delegator);
    }

    /**
     * The delegation under which the given model acts as a delegate at the moment
     * (now when omitted), if one is in force.
     */
    public function delegationFor(Model $delegate, ?CarbonInterface $at = null): ?ApprovalDelegation
    {
        return $this->container->make(DelegationResolver::class)->activeDelegationFor($delegate, $at);
    }

    /**
     * Lapse decisions (pending asks and approvals) and pending requests whose expiry is at
     * or before the moment (now when omitted) — only those on one subject type (a model
     * class or its morph alias) when given, app-wide otherwise.
     *
     * @return int the number of decisions and requests expired
     */
    public function expire(?CarbonInterface $now = null, ?string $subjectType = null): int
    {
        return $this->perform(
            ApprovalOperation::Expire,
            ExpireApprovalsAction::class,
            static fn (ExpireApprovalsAction $action): int => $action->execute($now, $subjectType),
            ['now' => $now, 'subjectType' => $subjectType],
        );
    }

    /**
     * Begin asking one named person to confirm one exact action on the subject, once:
     * `confirmation($subject)->payload($hash)->by($person)->expiresIn(...)->request()`.
     */
    public function confirmation(Model $subject): PendingConfirmation
    {
        return new PendingConfirmation($this, $subject);
    }

    /**
     * Confirm the confirmation as `$person` for the action whose payload hash is `$hash`.
     * Succeeds only for the named person and a matching hash, while it is pending and
     * unexpired, and uses it up atomically: of two concurrent confirms exactly one wins.
     *
     * @throws ConfirmationRefusedException `not_the_person`, `used`, `declined`, `expired` or `mismatch`
     */
    public function confirm(ApprovalConfirmation $confirmation, Model $person, string $hash): ApprovalConfirmation
    {
        return $this->perform(
            ApprovalOperation::Confirm,
            ConfirmAction::class,
            static fn (ConfirmAction $action): ApprovalConfirmation => $action->execute($confirmation, $person, $hash),
            ['confirmation' => $confirmation, 'person' => $person, 'reason' => null],
        );
    }

    /**
     * Decline the confirmation as `$person`, with an optional reason. Only the named
     * person can, while it is pending and unexpired; it uses the confirmation up.
     *
     * @throws ConfirmationRefusedException `not_the_person`, `used`, `declined` or `expired`
     */
    public function decline(ApprovalConfirmation $confirmation, Model $person, ?string $reason = null): ApprovalConfirmation
    {
        return $this->perform(
            ApprovalOperation::Decline,
            DeclineAction::class,
            static fn (DeclineAction $action): ApprovalConfirmation => $action->execute($confirmation, $person, $reason),
            ['confirmation' => $confirmation, 'person' => $person, 'reason' => $reason],
        );
    }

    /**
     * The confirmations waiting on the person: pending and not past their expiry.
     *
     * @return Builder<ApprovalConfirmation>
     */
    public function pendingConfirmationsFor(Model $person): Builder
    {
        return ApprovalConfirmationModelResolver::class()::query()->pendingFor($person);
    }

    /**
     * Every confirmation about the subject, whatever its status; chain `->pending()` for
     * the ones still open to an answer.
     *
     * @return Builder<ApprovalConfirmation>
     */
    public function confirmationsFor(Model $subject): Builder
    {
        return ApprovalConfirmationModelResolver::class()::query()->forSubject($subject);
    }

    /**
     * Lapse pending confirmations whose expiry is at or before the moment (now when
     * omitted) — only those about one subject type (a model class or its morph alias)
     * when given — firing ApprovalConfirmationExpired for each.
     *
     * @return int the number of confirmations expired
     */
    public function expireConfirmations(?CarbonInterface $now = null, ?string $subjectType = null): int
    {
        return $this->perform(
            ApprovalOperation::ExpireConfirmations,
            ExpireConfirmationsAction::class,
            static fn (ExpireConfirmationsAction $action): int => $action->execute($now, $subjectType),
            ['now' => $now, 'subjectType' => $subjectType],
        );
    }

    /**
     * Run one state-changing operation. Every builder, handle and model trait funnels
     * its terminal call through here, resolving the action from the container, so host
     * overrides apply and the fake records the call.
     *
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
        return $execute($this->container->make($action));
    }
}
