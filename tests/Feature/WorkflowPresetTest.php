<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Support\WorkflowResolver;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

beforeEach(function (): void {
    config()->set('approvals.workflows', [
        'payout' => [
            'rule' => ApprovalRule::Quorum->value,
            'quorum' => 2,
            'required_approvers' => 3,
            'expiry' => 86400,
        ],
        'release' => [
            'stages' => [
                ['rule' => ApprovalRule::Unanimous->value, 'required_approvers' => 2, 'name' => 'engineering'],
                ['rule' => ApprovalRule::Any->value, 'required_approvers' => 1, 'name' => 'product'],
            ],
        ],
    ]);
});

it('opens a flat request from a preset', function (): void {
    $budget = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();
    $b = ReviewerTestModel::create();
    $c = ReviewerTestModel::create();

    $request = Approvals::for($budget)->workflow('payout')->request([$a, $b, $c]);

    expect($request->rule)->toBe(ApprovalRule::Quorum)
        ->and($request->quorum)->toBe(2)
        ->and($request->required_approvers)->toBe(3)
        ->and($request->workflow)->toBe('payout')
        ->and($request->expires_at)->not->toBeNull();

    $a->approve($budget);
    $b->approve($budget);

    expect($budget->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('opens a staged request from a preset', function (): void {
    $release = ReleaseTestModel::create();
    $eng1 = ReviewerTestModel::create();
    $eng2 = ReviewerTestModel::create();
    $product = ReviewerTestModel::create();

    $request = Approvals::for($release)->workflow('release')->request([
        [$eng1, $eng2],
        [$product],
    ]);

    expect($request->staged)->toBeTrue()
        ->and($request->stages()->count())->toBe(2);

    $eng1->approve($release);
    $eng2->approve($release);
    $product->approve($release);

    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('throws on an unknown preset', function (): void {
    $release = ReleaseTestModel::create();

    expect(fn () => Approvals::for($release)->workflow('missing')->request())
        ->toThrow(UnknownWorkflowException::class);
});

it('throws when a staged preset gets the wrong number of approver groups', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();

    expect(fn () => Approvals::for($release)->workflow('release')->request([[$a]]))
        ->toThrow(UnknownWorkflowException::class);
});

it('validates a malformed staged preset', function (): void {
    config()->set('approvals.workflows.broken', ['stages' => [['rule' => ApprovalRule::Any->value]]]);

    expect(fn () => app(WorkflowResolver::class)->resolve('broken'))
        ->toThrow(UnknownWorkflowException::class);
});

it('validates non-integer quorum in a preset', function (): void {
    config()->set('approvals.workflows.bad', ['rule' => ApprovalRule::Quorum->value, 'quorum' => 'two']);

    expect(fn () => app(WorkflowResolver::class)->resolve('bad'))
        ->toThrow(UnknownWorkflowException::class);
});

it('validates an unknown rule in a preset', function (): void {
    config()->set('approvals.workflows.bad', ['rule' => 'nonsense']);

    expect(fn () => app(WorkflowResolver::class)->resolve('bad'))
        ->toThrow(UnknownWorkflowException::class);
});

it('validates that a preset is an array', function (): void {
    config()->set('approvals.workflows.bad', 'not-an-array');

    expect(fn () => app(WorkflowResolver::class)->resolve('bad'))
        ->toThrow(UnknownWorkflowException::class);
});

it('validates that stages is non-empty', function (): void {
    config()->set('approvals.workflows.bad', ['stages' => []]);

    expect(fn () => app(WorkflowResolver::class)->resolve('bad'))
        ->toThrow(UnknownWorkflowException::class);
});
