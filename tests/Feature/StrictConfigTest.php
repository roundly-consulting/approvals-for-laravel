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

it('refuses a non-string gate ability instead of checking the default one (strict config)', function (mixed $ability): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.authorization.ability', $ability);
    Gate::define('decide-approval', fn (): bool => true);

    expect(fn () => app(ApproveAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create()))
        ->toThrow(InvalidConfigurationException::class, 'approvals.authorization.ability')
        ->and(Approval::query()->count())->toBe(0);
})->with(['array' => [['decide-approval']], 'bool' => true, 'integer' => 1]);

it('checks the decide-approval gate when no ability is set (strict config)', function (?string $ability): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.authorization.ability', $ability);
    Gate::define('decide-approval', fn (): bool => true);

    Artisan::call('about', ['--only' => 'approvals']);

    expect(app(ApproveAction::class)->execute(ActorTestModel::create(), DeploymentTestModel::create()))
        ->toBeInstanceOf(Approval::class)
        ->and(Artisan::output())->toMatch('/Ability \.+ DEFAULT/');
})->with(['absent' => null, 'blank' => '', 'whitespace' => ' ']);

it('refuses a workflows registry that is not a map (strict config)', function (): void {
    config()->set('approvals.workflows', 'payout');

    expect(fn () => app(WorkflowResolver::class)->resolve('payout'))
        ->toThrow(InvalidConfigurationException::class, 'approvals.workflows');
});

it('reads an unset workflows registry as no presets (strict config)', function (?string $workflows): void {
    config()->set('approvals.workflows', $workflows);

    expect(WorkflowResolver::presets())->toBe([])
        ->and(fn () => app(WorkflowResolver::class)->resolve('payout'))
        ->toThrow(UnknownWorkflowException::class);
})->with(['absent' => null, 'blank' => '', 'whitespace' => ' ']);

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
    'blank (not set, never false)' => ['', true],
    'whitespace (not set)' => [' ', true],
]);

it('refuses a non-string stage name (strict config)', function (mixed $name): void {
    config()->set('approvals.workflows.release', [
        'stages' => [['rule' => ApprovalRule::Any->value, 'required_approvers' => 1, 'name' => $name]],
    ]);

    expect(fn () => app(WorkflowResolver::class)->resolve('release'))
        ->toThrow(UnknownWorkflowException::class, 'name');
})->with(['integer' => 7, 'array' => [['legal']]]);

it('reads blank optional preset keys as not set (strict config)', function (string $blank): void {
    config()->set('approvals.workflows.payout', ['rule' => $blank, 'quorum' => $blank, 'required_approvers' => $blank, 'expiry' => $blank]);
    config()->set('approvals.workflows.release', [
        'expiry' => $blank,
        'stages' => [['rule' => $blank, 'required_approvers' => 1, 'quorum' => $blank, 'name' => $blank]],
    ]);

    $flat = app(WorkflowResolver::class)->resolve('payout');
    $staged = app(WorkflowResolver::class)->resolve('release');

    expect($flat->rule)->toBe(ApprovalRule::Unanimous)
        ->and($flat->quorum)->toBeNull()
        ->and($flat->requiredApprovers)->toBeNull()
        ->and($flat->expiry)->toBeNull()
        ->and($staged->expiry)->toBeNull()
        ->and($staged->stages[0]->rule)->toBe(ApprovalRule::Unanimous)
        ->and($staged->stages[0]->quorum)->toBeNull()
        ->and($staged->stages[0]->name)->toBeNull();
})->with(['blank' => '', 'whitespace' => '  ']);

it('refuses an unknown or non-integer preset value (strict config)', function (array $preset, string $message): void {
    config()->set('approvals.workflows.payout', $preset);

    expect(fn () => app(WorkflowResolver::class)->resolve('payout'))
        ->toThrow(UnknownWorkflowException::class, $message);
})->with([
    'rule typo' => [['rule' => 'unanimus'], 'unknown rule'],
    'quorum word' => [['rule' => 'quorum', 'quorum' => 'two'], '[quorum] must be an integer'],
]);

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
