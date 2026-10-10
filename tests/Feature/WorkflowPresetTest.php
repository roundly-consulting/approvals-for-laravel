<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Actions\OpenWorkflowRequestAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\UnknownWorkflowException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
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

    $request = Approvals::request($budget)->workflow('payout')->open([$a, $b, $c]);

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

    $request = Approvals::request($release)->workflow('release')->open([
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

    expect(fn () => Approvals::request($release)->workflow('missing')->open())
        ->toThrow(UnknownWorkflowException::class);
});

it('throws when a staged preset gets the wrong number of approver groups', function (): void {
    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();

    expect(fn () => Approvals::request($release)->workflow('release')->open([[$a]]))
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

describe('a caller expiry on a preset round', function (): void {
    beforeEach(fn () => CarbonImmutable::setTestNow('2026-10-10 12:00:00'));

    afterEach(fn () => CarbonImmutable::setTestNow());

    $deadline = '2026-10-10 13:00:00';

    it('wins over a flat preset\'s own expiry when set before workflow()', function (string $setter) use ($deadline): void {
        $builder = Approvals::request(ReleaseTestModel::create());

        $setter === 'expiresIn'
            ? $builder->expiresIn(3600)
            : $builder->expiringAt(CarbonImmutable::parse($deadline));

        $request = $builder->workflow('payout')->open([
            ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(),
        ]);

        expect($request->expires_at?->toDateTimeString())->toBe($deadline)
            ->and($request->fresh()?->expires_at?->toDateTimeString())->toBe($deadline);
    })->with(['expiresIn', 'expiringAt']);

    it('can be set on the workflow builder itself', function (string $setter) use ($deadline): void {
        $workflow = Approvals::request(ReleaseTestModel::create())->workflow('payout');

        $setter === 'expiresIn'
            ? $workflow->expiresIn(3600)
            : $workflow->expiringAt(CarbonImmutable::parse($deadline));

        $request = $workflow->open([
            ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(),
        ]);

        expect($request->expires_at?->toDateTimeString())->toBe($deadline);
    })->with(['expiresIn', 'expiringAt']);

    it('takes the last expiry set, before or after workflow()', function () use ($deadline): void {
        $request = Approvals::request(ReleaseTestModel::create())
            ->expiresIn(60)
            ->workflow('payout')
            ->expiringAt(CarbonImmutable::parse($deadline))
            ->open([ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()]);

        expect($request->expires_at?->toDateTimeString())->toBe($deadline);
    });

    it('gives a staged preset one deadline for the whole round, every stage included', function () use ($deadline): void {
        $release = ReleaseTestModel::create();
        [$eng1, $eng2, $product] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->expiresIn(3600)->workflow('release')->open([[$eng1, $eng2], [$product]]);

        expect($request->expires_at?->toDateTimeString())->toBe($deadline);

        CarbonImmutable::setTestNow('2026-10-10 12:30:00');
        $eng1->approve($release);
        $eng2->approve($release);

        // Stage two opens later but keeps the round's deadline: it is not restarted per stage.
        expect(Approvals::currentStage($release)?->name)->toBe('product')
            ->and($request->fresh()?->expires_at?->toDateTimeString())->toBe($deadline);

        CarbonImmutable::setTestNow('2026-10-10 13:00:01');

        expect(Approvals::status($release))->toBe(ApprovalStatus::Expired);
    });

    it('leaves the preset\'s own expiry in place when the caller sets none', function (): void {
        $flat = Approvals::request(ReleaseTestModel::create())->workflow('payout')->open([
            ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(),
        ]);
        $staged = Approvals::request(ReleaseTestModel::create())->workflow('release')->open([
            [ReviewerTestModel::create(), ReviewerTestModel::create()], [ReviewerTestModel::create()],
        ]);

        expect($flat->expires_at?->toDateTimeString())->toBe('2026-10-11 12:00:00')
            ->and($staged->expires_at)->toBeNull();
    });

    it('reaches the round through an injected manager', function () use ($deadline): void {
        $request = app(ApprovalsManager::class)
            ->request(ReleaseTestModel::create())
            ->expiresIn(3600)
            ->workflow('payout')
            ->open([ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()]);

        expect($request->expires_at?->toDateTimeString())->toBe($deadline);
    });

    it('is recorded by the fake', function () use ($deadline): void {
        $fake = Approvals::fake();
        $release = ReleaseTestModel::create();

        Approvals::request($release)->expiresIn(3600)->workflow('payout')->open([
            ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create(),
        ]);

        $fake->assertOpened($release, 'payout');

        $opened = $fake->recorded(ApprovalOperation::Open)[0]->result;

        expect($opened)->toBeInstanceOf(ApprovalRequest::class)
            ->and($opened instanceof ApprovalRequest ? $opened->expires_at?->toDateTimeString() : null)->toBe($deadline);
    });

    it('is taken by the action, falling back to the preset\'s own', function () use ($deadline): void {
        $action = app(OpenWorkflowRequestAction::class);
        $approvers = fn (): array => [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $explicit = $action->execute(ReleaseTestModel::create(), Approvals::preset('payout'), $approvers(), CarbonImmutable::parse($deadline));
        $preset = $action->execute(ReleaseTestModel::create(), Approvals::preset('payout'), $approvers());

        expect($explicit->expires_at?->toDateTimeString())->toBe($deadline)
            ->and($preset->expires_at?->toDateTimeString())->toBe('2026-10-11 12:00:00');
    });
});
