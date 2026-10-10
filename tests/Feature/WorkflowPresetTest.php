<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Actions\OpenWorkflowRequestAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Builders\PendingApprovalRequest;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
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

/*
 * A preset defines the round's rule, quorum, stages and stage-rejection handling, and takes
 * its approvers in open(). workflow() used to drop every such setting made on the request
 * builder before it, so `->from([$cfo])->workflow('anyone')->open()` opened a round with no
 * named approvers that anyone could decide. It now refuses them; only the expiry carries over.
 */
describe('request-builder settings a preset defines', function (): void {
    $apply = static fn (PendingApprovalRequest $builder, string $setting): PendingApprovalRequest => match ($setting) {
        'from()' => $builder->from([ReviewerTestModel::create()]),
        'rule()' => $builder->rule(ApprovalRule::Quorum, 1),
        'any()' => $builder->any(),
        'quorum()' => $builder->quorum(1),
        'weighted()' => $builder->weighted(1),
        'stages()' => $builder->stages([new StageDefinition([ReviewerTestModel::create()], ApprovalRule::Any)]),
        'continueOnRejection()' => $builder->continueOnRejection(),
    };

    $refused = ['from()', 'rule()', 'any()', 'quorum()', 'weighted()', 'stages()', 'continueOnRejection()'];

    it('refuses :dataset before workflow() instead of dropping it', function (string $setting) use ($apply): void {
        $builder = $apply(Approvals::request(ReleaseTestModel::create()), $setting);

        expect(fn () => $builder->workflow('payout'))->toThrow(
            function (InvalidApprovalRequestException $e) use ($setting): void {
                expect($e->getMessage())
                    ->toContain('[payout]')
                    ->toContain("[{$setting}]")
                    ->toContain('defines');
            },
        );

        expect(ApprovalRequest::query()->count())->toBe(0);
    })->with($refused);

    it('refuses :dataset even when it repeats the default', function (string $setting): void {
        $builder = Approvals::request(ReleaseTestModel::create());

        match ($setting) {
            'from([])' => $builder->from([]),
            'rule(unanimous)' => $builder->rule(ApprovalRule::Unanimous),
            'stages([])' => $builder->stages([]),
            'continueOnRejection(false)' => $builder->continueOnRejection(false),
        };

        expect(fn () => $builder->workflow('payout'))->toThrow(InvalidApprovalRequestException::class);
    })->with(['from([])', 'rule(unanimous)', 'stages([])', 'continueOnRejection(false)']);

    it('names every refused setting once, in call order', function () use ($apply): void {
        $builder = Approvals::request(ReleaseTestModel::create())->quorum(1);
        $apply($builder, 'from()')->expiresIn(60)->quorum(2)->continueOnRejection();

        expect(fn () => $builder->workflow('payout'))
            ->toThrow(InvalidApprovalRequestException::class, '[quorum(), from(), continueOnRejection()]');
    });

    it('points named approvers at open()', function () use ($apply): void {
        $from = $apply(Approvals::request(ReleaseTestModel::create()), 'from()');
        $rule = $apply(Approvals::request(ReleaseTestModel::create()), 'any()');

        expect(fn () => $from->workflow('payout'))->toThrow(InvalidApprovalRequestException::class, 'open($approvers)')
            ->and(fn () => $rule->workflow('payout'))->toThrow(
                function (InvalidApprovalRequestException $e): void {
                    expect($e->getMessage())->not->toContain('open($approvers)');
                },
            );
    });

    it('never opens a round an outsider can decide', function (): void {
        config()->set('approvals.workflows.anyone', ['rule' => ApprovalRule::Any->value]);
        $payout = ReleaseTestModel::create();
        [$cfo, $outsider] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        expect(fn () => Approvals::request($payout)->from([$cfo])->workflow('anyone'))
            ->toThrow(InvalidApprovalRequestException::class);
        expect(ApprovalRequest::query()->count())->toBe(0);

        // The supported form names the approvers in open(), so only the CFO may decide.
        Approvals::request($payout)->workflow('anyone')->open([$cfo]);

        expect(fn () => $outsider->approve($payout))->toThrow(UnauthorizedApprovalException::class);

        $cfo->approve($payout);

        expect($payout->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
    });

    it('still opens from a bare workflow() and after an expiry only', function (): void {
        $approvers = fn (): array => [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $bare = Approvals::request(ReleaseTestModel::create())->workflow('payout')->open($approvers());
        $in = Approvals::request(ReleaseTestModel::create())->expiresIn(60)->workflow('payout')->open($approvers());
        $at = Approvals::request(ReleaseTestModel::create())->expiringAt(CarbonImmutable::now()->addHour())->workflow('payout')->open($approvers());

        expect([$bare->workflow, $in->workflow, $at->workflow])->toBe(['payout', 'payout', 'payout'])
            ->and($bare->namedApprovers())->toHaveCount(3)
            ->and(ApprovalRequest::query()->count())->toBe(3);
    });

    it('refuses through an injected manager too', function () use ($apply): void {
        $builder = $apply(app(ApprovalsManager::class)->request(ReleaseTestModel::create()), 'from()');

        expect(fn () => $builder->workflow('payout'))->toThrow(InvalidApprovalRequestException::class);
    });

    it('refuses the same way under the fake, recording nothing', function (string $setting) use ($apply): void {
        $fake = Approvals::fake();
        $builder = $apply(Approvals::request(ReleaseTestModel::create()), $setting);

        expect(fn () => $builder->workflow('payout'))->toThrow(InvalidApprovalRequestException::class, "[{$setting}]");

        $fake->assertNothingOpened();
    })->with($refused);
});
