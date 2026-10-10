<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ToggleApprovalAction;
use RoundlyConsulting\Approvals\DataTransferObjects\DecisionData;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalToggled;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| toggle() takes the decision builder's settings
|--------------------------------------------------------------------------
|
| toggle() used to drop because(), weight() and expiresIn() / expiringAt() silently.
| Toggling on records an approval, so it now takes all three the way approve() does;
| toggling off withdraws one, so it takes the reason the way cancel() does (a
| withdrawal has no weight or expiry). A bare toggle() works exactly as before.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

describe('toggling on', function (): void {
    it('records because() as the approval\'s reason', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        expect(Approvals::for($deployment)->as($reviewer)->because('Ship it')->toggle())->toBeTrue()
            ->and(Approval::query()->sole()->reason)->toBe('Ship it');
    });

    it('records weight() on the approval, and counts it towards the threshold', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        // Each named approver carries 1 on its own: the threshold of 2 needs the override.
        $request = $release->requestApproval([$lead, $qa], ApprovalRule::Weighted, quorum: 2);

        expect(Approvals::for($release)->as($lead)->weight(2)->toggle())->toBeTrue()
            ->and(Approval::query()->sole()->weight)->toBe(2)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('records expiresIn() as the approval\'s expiry', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())->expiresIn(3600)->toggle();

        expect(Approval::query()->sole()->expires_at?->toDateTimeString())->toBe('2026-10-10 13:00:00');
    });

    it('records expiringAt() in place of the default lifetime', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        config()->set('approvals.expiry.default', 60);

        Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())
            ->expiringAt(CarbonImmutable::parse('2026-10-17 12:00:00'))
            ->toggle();

        expect(Approval::query()->sole()->expires_at?->toDateTimeString())->toBe('2026-10-17 12:00:00');
    });

    it('is unchanged without settings', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        Event::fake([ApprovalToggled::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        expect(Approvals::for($deployment)->as($reviewer)->toggle())->toBeTrue();

        $approval = Approval::query()->sole();

        expect($approval->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->reason)->toBeNull()
            ->and($approval->weight)->toBe(1)
            ->and($approval->expires_at)->toBeNull()
            ->and($approval->approval_request_id)->toBeNull();

        Event::assertDispatched(ApprovalToggled::class, fn (ApprovalToggled $event): bool => $event->hasBeenApproved);
    });

    it('still stamps the default lifetime without settings', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');
        config()->set('approvals.expiry.default', 60);

        Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())->toggle();

        expect(Approval::query()->sole()->expires_at?->toDateTimeString())->toBe('2026-10-10 12:01:00');
    });
});

describe('toggling off', function (): void {
    it('records because() as the withdrawal\'s reason, as cancel() does', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $approval = Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();

        expect(Approvals::for($deployment)->as($reviewer)->because('Found a bug')->toggle())->toBeFalse();

        $withdrawn = Approval::withTrashed()->findOrFail($approval->getKey());

        expect($withdrawn->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn->trashed())->toBeTrue()
            ->and($withdrawn->reason)->toBe('Found a bug');
    });

    it('withdraws with weight() or an expiry set, recording no decision', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $approval = Approvals::for($deployment)->as($reviewer)->weight(2)->approve();

        expect(Approvals::for($deployment)->as($reviewer)->weight(5)->expiresIn(60)->toggle())->toBeFalse();

        $withdrawn = Approval::withTrashed()->findOrFail($approval->getKey());

        expect(Approval::withTrashed()->count())->toBe(1)
            ->and($withdrawn->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn->weight)->toBe(2)
            ->and($withdrawn->expires_at)->toBeNull();
    });

    it('is unchanged without settings: the approval keeps its reason', function (): void {
        Event::fake([ApprovalCancelled::class, ApprovalToggled::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $approval = Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();

        expect(Approvals::for($deployment)->as($reviewer)->toggle())->toBeFalse();

        $withdrawn = Approval::withTrashed()->findOrFail($approval->getKey());

        expect($withdrawn->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn->trashed())->toBeTrue()
            ->and($withdrawn->reason)->toBe('Ship it');

        Event::assertDispatchedTimes(ApprovalCancelled::class, 1);
        Event::assertDispatched(ApprovalToggled::class, fn (ApprovalToggled $event): bool => ! $event->hasBeenApproved);
    });
});

describe('the action', function (): void {
    it('takes the decision data and the request to pin', function (): void {
        $release = ReleaseTestModel::create();
        [$lead, $qa] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $older = Approvals::request($release)->from([$lead, $qa])->open();
        Approvals::request($release)->from([$lead, $qa])->open();

        $toggle = app(ToggleApprovalAction::class);

        expect($toggle->execute($lead, $release, DecisionData::approved('Ship it', null, 2), $older))->toBeTrue();

        $approval = Approval::query()->sole();

        expect($approval->approval_request_id)->toBe($older->getKey())
            ->and($approval->reason)->toBe('Ship it')
            ->and($approval->weight)->toBe(2)
            ->and($toggle->execute($lead, $release, DecisionData::approved('Not yet'), $older))->toBeFalse()
            ->and(Approval::withTrashed()->findOrFail($approval->getKey())->reason)->toBe('Not yet');
    });
});

describe('under the fake', function (): void {
    it('carries the settings and records the toggle', function (): void {
        $fake = Approvals::fake();

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        expect(Approvals::for($deployment)->as($reviewer)->because('Ship it')->weight(2)->toggle())->toBeTrue();

        $approval = Approval::query()->sole();

        expect($approval->reason)->toBe('Ship it')
            ->and($approval->weight)->toBe(2)
            ->and($fake->recorded(ApprovalOperation::Toggle))->toHaveCount(1)
            ->and($fake->recorded(ApprovalOperation::Toggle)[0]->context['reason'])->toBe('Ship it');

        $fake->assertToggled($deployment, by: $reviewer);
    });

    it('refuses a pinned closed round, recording nothing', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $closed = Approvals::request($release)->from([$lead])->open();
        Approvals::for($release)->within($closed)->close();
        $open = Approvals::request($release)->from([$lead])->open();

        expect(fn () => Approvals::for($release)->as($lead)->within($closed)->toggle())
            ->toThrow(ClosedApprovalRequestException::class, 'is closed (cancelled)')
            ->and(Approval::query()->withTrashed()->count())->toBe(0)
            ->and($open->fresh()?->status)->toBe(ApprovalStatus::Pending);

        $fake->assertNothingToggled();
    });
});
