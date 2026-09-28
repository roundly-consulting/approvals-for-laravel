<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Actions\ApproveAction;
use RoundlyConsulting\Approvals\Actions\RequestApprovalAction;
use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Builders\DelegationsHandle;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Builders\PendingApprovalRequest;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\DataTransferObjects\WorkflowPreset;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

afterEach(fn () => CarbonImmutable::setTestNow());

it('documents its root, is fakeable and reaches every action', function (): void {
    expect(Approvals::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

describe('the manager', function (): void {
    it('is a container singleton', function (): void {
        expect(app(ApprovalsManager::class))->toBe(app(ApprovalsManager::class))
            ->and(Approvals::getFacadeRoot())->toBe(app(ApprovalsManager::class));
    });

    it('works injected, without the facade', function (): void {
        $manager = app(ApprovalsManager::class);
        $release = ReleaseTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $manager->request($release)->from([$reviewer])->open();
        $manager->for($release)->as($reviewer)->approve();

        expect($manager->status($release))->toBe(ApprovalStatus::Approved);
    });

    it('is returned by the approvals() helper', function (): void {
        expect(approvals())->toBe(app(ApprovalsManager::class));
    });

    it('registers the Approvals alias', function (): void {
        expect(class_exists('Approvals'))->toBeTrue();
    });
});

describe('decisions', function (): void {
    it('starts a builder from either side', function (): void {
        expect(Approvals::for(DeploymentTestModel::create()))->toBeInstanceOf(PendingApproval::class)
            ->and(Approvals::as(ActorTestModel::create()))->toBeInstanceOf(PendingApproval::class);
    });

    it('approves with a reason and a weight override', function (): void {
        $deployment = DeploymentTestModel::create();
        $actor = ActorTestModel::create();

        $approval = Approvals::for($deployment)->as($actor)->because('LGTM')->weight(3)->approve();

        expect($approval->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->reason)->toBe('LGTM')
            ->and($approval->weight)->toBe(3);
    });

    it('rejects with a weight override', function (): void {
        $approval = Approvals::for(DeploymentTestModel::create())->as(ActorTestModel::create())->weight(2)->reject();

        expect($approval->status)->toBe(ApprovalStatus::Rejected)
            ->and($approval->weight)->toBe(2);
    });

    it('asks an actor for a decision', function (): void {
        $approval = Approvals::for(DeploymentTestModel::create())->as(ActorTestModel::create())->expiresIn(60)->ask();

        expect($approval->status)->toBe(ApprovalStatus::Pending)
            ->and($approval->expires_at)->not->toBeNull();
    });

    it('cancels and toggles', function (): void {
        $deployment = DeploymentTestModel::create();
        $actor = ActorTestModel::create();

        Approvals::for($deployment)->as($actor)->approve();

        $toggler = ActorTestModel::create();

        expect(Approvals::for($deployment)->as($actor)->because('changed my mind')->cancel()?->status)
            ->toBe(ApprovalStatus::Cancelled)
            ->and(Approvals::for($deployment)->as($toggler)->toggle())->toBeTrue()
            ->and(Approvals::for($deployment)->as($toggler)->toggle())->toBeFalse();
    });

    it('reads decisions', function (): void {
        $deployment = DeploymentTestModel::create();
        $yes = ActorTestModel::create();
        $no = ActorTestModel::create();
        $asked = ActorTestModel::create();

        expect(Approvals::for($deployment)->hasPending())->toBeFalse();

        Approvals::for($deployment)->as($yes)->approve();
        Approvals::for($deployment)->as($no)->reject();
        Approvals::for($deployment)->as($asked)->ask();

        expect(Approvals::for($deployment)->as($yes)->isApproved())->toBeTrue()
            ->and(Approvals::for($deployment)->as($yes)->isRejected())->toBeFalse()
            ->and(Approvals::for($deployment)->as($no)->isRejected())->toBeTrue()
            ->and(Approvals::for($deployment)->as($no)->isApproved())->toBeFalse()
            ->and(Approvals::for($deployment)->hasPending())->toBeTrue();
    });

    it('pins a decision to an explicit request with within()', function (): void {
        $release = ReleaseTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $older = Approvals::request($release)->from([$reviewer])->open();
        Approvals::request($release)->from([$reviewer])->open();

        $approval = Approvals::for($release)->as($reviewer)->within($older)->approve();

        expect($approval->approval_request_id)->toBe($older->getKey())
            ->and($older->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('pins an asked decision to an explicit request', function (): void {
        $release = ReleaseTestModel::create();
        $reviewer = ReviewerTestModel::create();
        $request = Approvals::request($release)->from([$reviewer])->open();

        $approval = Approvals::for($release)->as($reviewer)->within($request)->ask();

        expect($approval->approval_request_id)->toBe($request->getKey());
    });
});

describe('cross-scope refusal', function (): void {
    it('refuses a request that belongs to another subject', function (string $verb): void {
        $release = ReleaseTestModel::create();
        $other = ReleaseTestModel::create();
        $reviewer = ReviewerTestModel::create();
        $foreign = Approvals::request($other)->from([$reviewer])->open();

        expect(fn () => Approvals::for($release)->as($reviewer)->within($foreign)->{$verb}())
            ->toThrow(InvalidApprovalRequestException::class, 'does not belong to the approvable');

        expect(Approval::query()->count())->toBe(0)
            ->and($foreign->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with(['approve', 'reject', 'ask']);

    it('refuses a request of the same key but another morph type', function (): void {
        $release = ReleaseTestModel::create();
        $deployment = DeploymentTestModel::create(['id' => $release->getKey()]);
        $reviewer = ReviewerTestModel::create();

        $foreign = Approvals::request($release)->from([$reviewer])->open();

        expect(fn () => Approvals::for($deployment)->as($reviewer)->within($foreign)->approve())
            ->toThrow(InvalidApprovalRequestException::class);
    });

    it('refuses a foreign request at the action level too', function (): void {
        $release = ReleaseTestModel::create();
        $reviewer = ReviewerTestModel::create();
        $foreign = Approvals::request(ReleaseTestModel::create())->from([$reviewer])->open();

        expect(fn () => app(ApproveAction::class)->execute($reviewer, $release, null, $foreign))
            ->toThrow(InvalidApprovalRequestException::class)
            ->and(fn () => app(RequestApprovalAction::class)->execute($reviewer, $release, null, $foreign))
            ->toThrow(InvalidApprovalRequestException::class);
    });

    it('scopes revocation to the delegator', function (): void {
        $boss = ReviewerTestModel::create();
        $otherBoss = ReviewerTestModel::create();
        $deputy = ReviewerTestModel::create();

        Approvals::delegations($boss)->to($deputy)->grant();

        expect(Approvals::delegations($otherBoss)->revoke($deputy))->toBe(0)
            ->and(Approvals::delegations($boss)->active())->toHaveCount(1)
            ->and(Approvals::delegationFor($deputy)?->delegator_id)->toBe($boss->getKey());
    });
});

describe('requests', function (): void {
    it('opens a flat quorum request', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$a, $b, $c])->quorum(2)->expiresIn(3600)->open();

        expect(Approvals::request($release))->toBeInstanceOf(PendingApprovalRequest::class)
            ->and($request->rule)->toBe(ApprovalRule::Quorum)
            ->and($request->quorum)->toBe(2)
            ->and($request->required_approvers)->toBe(3)
            ->and($request->fresh()?->staged)->toBeFalse()
            ->and($request->expires_at)->not->toBeNull();

        $a->approve($release);
        expect(Approvals::status($release))->toBe(ApprovalStatus::Pending);

        $b->approve($release);
        expect(Approvals::status($release))->toBe(ApprovalStatus::Approved);
    });

    it('sets the rule', function (Closure $configure, ApprovalRule $rule, ?int $quorum): void {
        $pending = Approvals::request(ReleaseTestModel::create())->from([ReviewerTestModel::create()]);

        $request = $configure($pending)->open();

        expect($request->rule)->toBe($rule)
            ->and($request->quorum)->toBe($quorum);
    })->with([
        'any' => [fn (PendingApprovalRequest $p): PendingApprovalRequest => $p->any(), ApprovalRule::Any, null],
        'weighted' => [fn (PendingApprovalRequest $p): PendingApprovalRequest => $p->weighted(1), ApprovalRule::Weighted, 1],
        'rule' => [fn (PendingApprovalRequest $p): PendingApprovalRequest => $p->quorum(3)->rule(ApprovalRule::Unanimous), ApprovalRule::Unanimous, null],
    ]);

    it('opens a staged request that continues on rejection and expires', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $release = ReleaseTestModel::create();
        $eng = ReviewerTestModel::create();
        $product = ReviewerTestModel::create();
        $expires = CarbonImmutable::now()->addWeek();

        $request = Approvals::request($release)
            ->stages([
                new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
                new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
            ])
            ->continueOnRejection()
            ->expiringAt($expires)
            ->open();

        expect($request->staged)->toBeTrue()
            ->and($request->reject_on_stage_rejection)->toBeFalse()
            ->and($request->expires_at?->equalTo($expires))->toBeTrue()
            ->and(Approvals::currentStage($release)?->name)->toBe('engineering');

        $eng->reject($release);

        expect(Approvals::currentStage($release)?->name)->toBe('product')
            ->and(Approvals::status($release))->toBe(ApprovalStatus::Pending);
    });

    it('refuses flat approvers and stages together', function (): void {
        $reviewer = ReviewerTestModel::create();

        expect(fn () => Approvals::request(ReleaseTestModel::create())
            ->from([$reviewer])
            ->stages([new StageDefinition([$reviewer])])
            ->open())->toThrow(InvalidApprovalRequestException::class, 'not both');
    });

    it('opens a request from a workflow preset and reads the preset', function (): void {
        config()->set('approvals.workflows.purchase', ['rule' => ApprovalRule::Any->value, 'expiry' => 60]);

        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->workflow('purchase')->open([ReviewerTestModel::create()]);

        expect($request->workflow)->toBe('purchase')
            ->and($request->rule)->toBe(ApprovalRule::Any)
            ->and(Approvals::preset('purchase'))->toBeInstanceOf(WorkflowPreset::class)
            ->and(Approvals::preset('purchase')->expiry)->toBe(60);
    });
});

describe('reads', function (): void {
    it('reports status, progress and stage for a subject without a request', function (): void {
        $release = ReleaseTestModel::create();

        expect(Approvals::status($release))->toBe(ApprovalStatus::Pending)
            ->and(Approvals::progress($release))->toBeNull()
            ->and(Approvals::currentStage($release))->toBeNull();
    });

    it('reports progress of the latest request', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::request($release)->from([$a, $b])->open();
        $a->approve($release);

        $progress = Approvals::progress($release);

        expect($progress?->approved)->toBe(1)
            ->and($progress?->required)->toBe(2)
            ->and($progress?->percentage())->toBe(50);
    });
});

describe('delegations', function (): void {
    it('grants a windowed delegation', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $boss = ReviewerTestModel::create();
        $deputy = ReviewerTestModel::create();
        $from = CarbonImmutable::now()->addHour();
        $until = CarbonImmutable::now()->addDay();

        expect(Approvals::delegations($boss))->toBeInstanceOf(DelegationsHandle::class);

        $delegation = Approvals::delegations($boss)->to($deputy)->from($from)->until($until)->grant();

        expect($delegation)->toBeInstanceOf(ApprovalDelegation::class)
            ->and($delegation->starts_at?->equalTo($from))->toBeTrue()
            ->and($delegation->ends_at?->equalTo($until))->toBeTrue()
            ->and(Approvals::delegations($boss)->active())->toHaveCount(0)
            ->and(Approvals::delegations($boss)->active($from->addMinute()))->toHaveCount(1)
            ->and(Approvals::delegationFor($deputy))->toBeNull()
            ->and(Approvals::delegationFor($deputy, $from->addMinute())?->is($delegation))->toBeTrue();
    });

    it('measures for() from the start of the window', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $from = CarbonImmutable::now()->addDay();

        $delegation = Approvals::delegations(ReviewerTestModel::create())
            ->to(ReviewerTestModel::create())
            ->from($from)
            ->for(3600)
            ->grant();

        expect($delegation->ends_at?->equalTo($from->addHour()))->toBeTrue();
    });

    it('lets the last of until() and for() win', function (): void {
        CarbonImmutable::setTestNow('2026-09-28 12:00:00');

        $until = CarbonImmutable::now()->addWeek();

        $delegation = Approvals::delegations(ReviewerTestModel::create())
            ->to(ReviewerTestModel::create())
            ->for(60)
            ->until($until)
            ->grant();

        expect($delegation->ends_at?->equalTo($until))->toBeTrue();
    });

    it('revokes one delegate or all', function (): void {
        $boss = ReviewerTestModel::create();
        [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::delegations($boss)->to($a)->grant();
        Approvals::delegations($boss)->to($b)->grant();

        expect(Approvals::delegations($boss)->revoke($a))->toBe(1)
            ->and(Approvals::delegations($boss)->active())->toHaveCount(1)
            ->and(Approvals::delegations($boss)->revoke())->toBe(1)
            ->and(Approvals::delegations($boss)->active())->toHaveCount(0);
    });
});

it('expires lapsed decisions', function (): void {
    Approvals::for(DeploymentTestModel::create())->as(ActorTestModel::create())->ask();
    Approval::query()->update(['expires_at' => now()->subDay()]);

    expect(Approvals::expire())->toBe(1)
        ->and(Approvals::expire(CarbonImmutable::now()))->toBe(0);
});
