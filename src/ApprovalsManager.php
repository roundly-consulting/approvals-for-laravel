<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Actions\ExpireApprovalsAction;
use RoundlyConsulting\Approvals\Builders\DelegationsHandle;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Builders\PendingApprovalRequest;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalProgress;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
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
     * Lapse pending decisions whose expiry is at or before the moment (now when omitted).
     *
     * @return int the number of decisions expired
     */
    public function expire(?CarbonInterface $now = null): int
    {
        return $this->perform(
            ApprovalOperation::Expire,
            ExpireApprovalsAction::class,
            static fn (ExpireApprovalsAction $action): int => $action->execute($now),
            ['now' => $now],
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
