<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\WorkflowResolver;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 | A typo in a host's config must fail loudly, never quietly become a default. A gate
 | ability that was not a string used to become `decide-approval` — a different gate than
 | the host configured — and a workflow's `reject_on_stage_rejection` read anything but a
 | literal `false` (`'false'`, `0`) as true.
 */

it('refuses a blank or non-string gate ability instead of checking the default one (strict config)', function (mixed $ability): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.authorization.ability', $ability);
    Gate::define('decide-approval', fn (): bool => true);

    expect(fn () => app(ApproveAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create()))
        ->toThrow(InvalidConfigurationException::class, 'approvals.authorization.ability')
        ->and(Approval::query()->count())->toBe(0);
})->with(['blank' => '', 'whitespace' => ' ', 'array' => [['decide-approval']], 'bool' => true]);

it('checks the decide-approval gate when no ability is configured (strict config)', function (): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.authorization.ability', null);
    Gate::define('decide-approval', fn (): bool => true);

    expect(app(ApproveAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create()))
        ->toBeInstanceOf(Approval::class);
});

it('refuses a workflows registry that is not a map (strict config)', function (): void {
    config()->set('approvals.workflows', 'payout');

    expect(fn () => app(WorkflowResolver::class)->resolve('payout'))
        ->toThrow(InvalidConfigurationException::class, 'approvals.workflows');
});

it('refuses a non-boolean reject_on_stage_rejection (strict config)', function (mixed $value): void {
    config()->set('approvals.workflows.release', [
        'reject_on_stage_rejection' => $value,
        'stages' => [['rule' => ApprovalRule::Any->value, 'required_approvers' => 1]],
    ]);

    expect(fn () => app(WorkflowResolver::class)->resolve('release'))
        ->toThrow(UnknownWorkflowException::class, 'reject_on_stage_rejection');
})->with(['typo' => 'flase', 'two' => 2, 'array' => [[false]]]);

it('reads boolean spellings of reject_on_stage_rejection (strict config)', function (mixed $value, bool $expected): void {
    config()->set('approvals.workflows.release', [
        'reject_on_stage_rejection' => $value,
        'stages' => [['rule' => ApprovalRule::Any->value, 'required_approvers' => 1]],
    ]);

    expect(app(WorkflowResolver::class)->resolve('release')->rejectOnStageRejection)->toBe($expected);
})->with([
    'false string' => ['false', false],
    'zero' => [0, false],
    'true' => [true, true],
    'absent' => [null, true],
]);

it('refuses a blank or non-string stage name (strict config)', function (mixed $name): void {
    config()->set('approvals.workflows.release', [
        'stages' => [['rule' => ApprovalRule::Any->value, 'required_approvers' => 1, 'name' => $name]],
    ]);

    expect(fn () => app(WorkflowResolver::class)->resolve('release'))
        ->toThrow(UnknownWorkflowException::class, 'name');
})->with(['blank' => '', 'integer' => 7]);

it('refuses a non-positive preset expiry (strict config)', function (int $expiry): void {
    config()->set('approvals.workflows.payout', ['rule' => ApprovalRule::Any->value, 'expiry' => $expiry]);

    expect(fn () => app(WorkflowResolver::class)->resolve('payout'))
        ->toThrow(UnknownWorkflowException::class, 'expiry');
})->with(['zero' => 0, 'negative' => -60]);

it('keeps the about section rendering on a malformed host config (strict config)', function (): void {
    config()->set('approvals.authorization.ability', ['decide']);
    config()->set('approvals.expiry.default', 'a day');
    config()->set('approvals.workflows', 'payout');

    Artisan::call('about', ['--only' => 'approvals']);

    expect(Artisan::output())
        ->toMatch('/Ability \.+ INVALID/')
        ->toMatch('/Default expiry \.+ INVALID/')
        ->toMatch('/Workflow presets \.+ INVALID/');
});
