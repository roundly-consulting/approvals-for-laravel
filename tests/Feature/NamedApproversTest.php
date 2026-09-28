<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use RoundlyConsulting\Approvals\DataTransferObjects\NamedApprover;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/**
 * A request only counts decisions from the approvers it names — persisted per request
 * (flat) and per stage (staged) — or from their delegates. The approver list used to be
 * reduced to a headcount, so any N actors could satisfy, veto or clear a request.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function namedReviewers(int $count): array
{
    return array_map(fn (): ReviewerTestModel => ReviewerTestModel::create(), range(1, $count));
}

describe('persistence', function (): void {
    it('stores each named approver once, with its weight', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = namedReviewers(2);

        $request = $release->requestApproval([$lead, $qa, $lead], ApprovalRule::Unanimous);
        $stored = $request->fresh()?->namedApprovers() ?? [];

        expect($stored)->toHaveCount(2)
            ->and($stored[0])->toEqual(new NamedApprover($lead->getMorphClass(), $lead->getKey(), 1))
            ->and($request->required_approvers)->toBe(2)
            ->and($request->hasNamedApprover($qa))->toBeTrue()
            ->and($request->hasNamedApprover(ReviewerTestModel::create()))->toBeFalse();
    });

    it('stores the approvers per stage', function (): void {
        $release = ReleaseTestModel::create();
        [$eng, $product] = namedReviewers(2);

        $request = $release->requestStagedApproval([
            new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
            new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
        ]);

        [$first, $second] = $request->stages()->get()->all();

        expect($request->namedApprovers())->toBe([])
            ->and($first->hasNamedApprover($eng))->toBeTrue()
            ->and($first->hasNamedApprover($product))->toBeFalse()
            ->and($second->hasNamedApprover($product))->toBeTrue()
            ->and($request->hasNamedApprover($eng))->toBeTrue()
            ->and($request->hasNamedApprover($product))->toBeFalse();
    });

    it('refuses an approver that was never saved', function (): void {
        expect(fn () => ReleaseTestModel::create()->requestApproval([new ReviewerTestModel]))
            ->toThrow(InvalidApprovalRequestException::class, 'must be saved');
    });

    it('refuses to require more approvals than it names', function (): void {
        config()->set('approvals.workflows.payout', ['rule' => 'quorum', 'quorum' => 2, 'required_approvers' => 3]);

        expect(fn () => Approvals::request(ReleaseTestModel::create())->workflow('payout')->open(namedReviewers(2)))
            ->toThrow(InvalidApprovalRequestException::class, 'cannot require 3 approvals from 2 named approver(s)');
    });

    it('skips malformed stored approvers', function (): void {
        expect(NamedApprover::listFrom([['type' => 'user', 'id' => 7], 'junk', ['type' => 1, 'id' => 2], ['id' => 3]]))
            ->toEqual([new NamedApprover('user', 7, 1)])
            ->and(NamedApprover::listFrom('not a list'))->toBe([]);
    });
});

describe('outsiders are refused', function (): void {
    it('refuses outsiders on a quorum request', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa, $pm, $outsider1, $outsider2] = namedReviewers(5);

        $release->requestApproval([$lead, $qa, $pm], ApprovalRule::Quorum, quorum: 2);

        expect(fn () => $outsider1->approve($release))->toThrow(UnauthorizedApprovalException::class, 'not a named approver')
            ->and(fn () => $outsider2->approve($release))->toThrow(UnauthorizedApprovalException::class)
            ->and(Approval::query()->count())->toBe(0)
            ->and($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);
    });

    it('refuses an outsider veto on a unanimous request', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa, $outsider] = namedReviewers(3);

        $release->requestApproval([$lead, $qa], ApprovalRule::Unanimous);

        expect(fn () => $outsider->reject($release))->toThrow(UnauthorizedApprovalException::class)
            ->and($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);
    });

    it('refuses toggles, asks and pinned decisions from outsiders', function (string $verb): void {
        $release = ReleaseTestModel::create();
        [$lead, $outsider] = namedReviewers(2);

        $request = $release->requestApproval([$lead]);

        expect(fn () => Approvals::for($release)->as($outsider)->within($request)->{$verb}())
            ->toThrow(UnauthorizedApprovalException::class)
            ->and(Approval::query()->count())->toBe(0);
    })->with(['approve', 'reject', 'ask', 'toggle']);

    it('only lets the open stage\'s approvers decide it', function (): void {
        $release = ReleaseTestModel::create();
        [$eng1, $eng2, $product, $outsider] = namedReviewers(4);

        $release->requestStagedApproval([
            new StageDefinition([$eng1, $eng2], ApprovalRule::Unanimous, name: 'engineering'),
            new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
        ]);

        expect(fn () => $product->approve($release))->toThrow(UnauthorizedApprovalException::class, 'stage 1 [engineering]')
            ->and(fn () => $outsider->approve($release))->toThrow(UnauthorizedApprovalException::class);

        $eng1->approve($release);
        expect(Approvals::currentStage($release)?->name)->toBe('engineering');

        $eng2->approve($release);
        expect(Approvals::currentStage($release)?->name)->toBe('product');

        $product->approve($release);
        expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
    });
});

describe('delegation', function (): void {
    it('lets a delegate decide for a named approver', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $qa, $deputy] = namedReviewers(3);

        $release->requestApproval([$boss, $qa], ApprovalRule::Unanimous);
        $boss->delegateApprovalsTo($deputy);

        $approval = $deputy->approve($release);
        $qa->approve($release);

        expect($approval->actor_id)->toBe($boss->getKey())
            ->and($approval->decided_by_id)->toBe($deputy->getKey())
            ->and($release->isApproved())->toBeTrue();
    });

    it('refuses a delegate whose delegator is not named', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $outsiderBoss, $deputy] = namedReviewers(3);

        $release->requestApproval([$lead]);
        $outsiderBoss->delegateApprovalsTo($deputy);

        expect(fn () => $deputy->approve($release))->toThrow(UnauthorizedApprovalException::class);
    });

    it('lets a named approver who is also a delegate decide as itself', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $deputy] = namedReviewers(2);

        $release->requestApproval([$boss, $deputy], ApprovalRule::Unanimous);
        $boss->delegateApprovalsTo($deputy);

        $boss->approve($release);
        $own = $deputy->approve($release);

        expect($own->actor_id)->toBe($deputy->getKey())
            ->and($own->decided_by_id)->toBeNull()
            ->and($release->isApproved())->toBeTrue();
    });
});

describe('requests opened without names', function (): void {
    it('lets any approver decide, as before', function (): void {
        $release = ReleaseTestModel::create();
        [$anyone, $boss, $deputy] = namedReviewers(3);

        ApprovalRequest::factory()->forSubject($release)->create(['required_approvers' => 2]);
        $boss->delegateApprovalsTo($deputy);

        $anyone->approve($release);
        $delegated = $deputy->approve($release);

        expect($delegated->actor_id)->toBe($boss->getKey())
            ->and($release->isApproved())->toBeTrue();
    });

    it('lets any approver decide a stage opened without names', function (): void {
        $release = ReleaseTestModel::create();
        $anyone = ReviewerTestModel::create();

        $release->requestStagedApproval([new StageDefinition([], ApprovalRule::Any, requiredApprovers: 1)]);

        $anyone->approve($release);

        expect($release->isApproved())->toBeTrue();
    });
});

describe('workflow stage headcount', function (): void {
    beforeEach(function (): void {
        Config::set('approvals.workflows.release', [
            'stages' => [
                ['rule' => ApprovalRule::Unanimous->value, 'required_approvers' => 2, 'name' => 'engineering'],
                ['rule' => ApprovalRule::Any->value, 'required_approvers' => 1, 'name' => 'product'],
            ],
        ]);
    });

    it('stores each stage\'s required approvers from the preset', function (): void {
        $release = ReleaseTestModel::create();
        [$eng1, $eng2, $eng3, $product] = namedReviewers(4);

        $request = Approvals::request($release)->workflow('release')->open([[$eng1, $eng2, $eng3], [$product]]);

        $engineering = $request->stages()->first();

        $eng1->approve($release);

        expect($engineering?->required_approvers)->toBe(2)
            ->and(Approvals::currentStage($release)?->name)->toBe('engineering');

        $eng2->approve($release);

        expect(Approvals::currentStage($release)?->name)->toBe('product');
    });

    it('refuses a stage group smaller than the preset requires', function (): void {
        [$eng, $product] = namedReviewers(2);

        expect(fn () => Approvals::request(ReleaseTestModel::create())->workflow('release')->open([[$eng], [$product]]))
            ->toThrow(InvalidApprovalRequestException::class)
            ->and(ApprovalRequest::query()->count())->toBe(0);
    });
});
