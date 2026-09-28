<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalExpired;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

beforeEach(fn () => CarbonImmutable::setTestNow('2026-09-28 12:00:00'));

afterEach(fn () => CarbonImmutable::setTestNow());

function twoDaysLater(): void
{
    CarbonImmutable::setTestNow('2026-09-30 12:00:00');
}

describe('an expiring approval', function (): void {
    it('stops counting once its expiry passes, and the sweep lapses it', function (): void {
        $deployment = DeploymentTestModel::create();
        $cfo = ReviewerTestModel::create();

        Approvals::for($deployment)->as($cfo)->expiresIn(60)->approve();

        twoDaysLater();

        expect(Approvals::for($deployment)->as($cfo)->isApproved())->toBeFalse()
            ->and($deployment->hasBeenApprovedBy($cfo))->toBeFalse()
            ->and($cfo->hasApproved($deployment))->toBeFalse()
            ->and($deployment->approvalCount())->toBe(0);

        Event::fake([ApprovalExpired::class]);

        expect(Approvals::expire())->toBe(1)
            ->and(Approval::query()->sole()->status)->toBe(ApprovalStatus::Expired);

        Event::assertDispatched(ApprovalExpired::class);
    });

    it('stops counting towards a request once it lapses', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $b, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        $release->requestApproval([$a, $b, $c], ApprovalRule::Quorum, quorum: 2);

        Approvals::for($release)->as($a)->expiresIn(60)->approve();

        twoDaysLater();

        $b->approve($release);

        expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

        // `$a` can sign off again: the lapsed approval freed its slot.
        $a->approve($release);

        expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved)
            ->and(Approval::query()->expired()->count())->toBe(1);
    });

    it('does not inherit the deadline of the ask it answers', function (): void {
        $deployment = DeploymentTestModel::create();
        $cfo = ReviewerTestModel::create();

        Approvals::for($deployment)->as($cfo)->expiresIn(60)->ask();
        $approval = $cfo->approve($deployment);

        expect($approval->expires_at)->toBeNull();
    });
});

describe('the default approval lifetime', function (): void {
    it('stamps approvals with expiry.default', function (int|string $configured): void {
        config()->set('approvals.expiry.default', $configured);

        $approval = ReviewerTestModel::create()->approve(DeploymentTestModel::create());

        expect($approval->expires_at?->toDateTimeString())->toBe('2026-09-28 13:00:00');
    })->with([3600, '3600']);

    it('lets an explicit expiry win over the default', function (): void {
        config()->set('approvals.expiry.default', 3600);

        $approval = Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())->expiresIn(60)->approve();

        expect($approval->expires_at?->toDateTimeString())->toBe('2026-09-28 12:01:00');
    });

    it('never expires approvals when unset', function (): void {
        config()->set('approvals.expiry.default', null);

        expect(ReviewerTestModel::create()->approve(DeploymentTestModel::create())->expires_at)->toBeNull();
    });

    it('refuses a malformed default', function (): void {
        config()->set('approvals.expiry.default', 'soon');

        ReviewerTestModel::create()->approve(DeploymentTestModel::create());
    })->throws(InvalidConfigurationException::class);
});

describe('an expiring request', function (): void {
    it('is lapsed by the sweep and resolves as expired', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->expiresIn(60)->open();

        twoDaysLater();

        Event::fake([ApprovalRequestResolved::class]);

        expect(Approvals::expire())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired);

        Event::assertDispatched(ApprovalRequestResolved::class, fn (ApprovalRequestResolved $event): bool => $event->request->status === ApprovalStatus::Expired);
    });

    it('stops accepting decisions once overdue, even before the sweep', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->any()->expiresIn(60)->open();

        twoDaysLater();

        expect(Approvals::status($release))->toBe(ApprovalStatus::Expired)
            ->and($release->isPendingApproval())->toBeFalse();

        $approval = $lead->approve($release);

        expect($approval->approval_request_id)->toBeNull()
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired);
    });

    it('refuses a decision pinned to an overdue request', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->expiresIn(60)->open();

        twoDaysLater();

        expect(fn () => Approvals::for($release)->as($lead)->within($request)->approve())
            ->toThrow(InvalidApprovalRequestException::class, 'expired')
            ->and(Approval::query()->count())->toBe(0);
    });

    it('resolves an overdue request as expired whatever its decisions say', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->any()->expiresIn(60)->open();

        twoDaysLater();

        expect($request->resolve()->status)->toBe(ApprovalStatus::Expired)
            ->and($request->lapseIfOverdue())->toBeFalse();
    });

    it('lapses a workflow preset\'s expiry too', function (): void {
        config()->set('approvals.workflows.payout', ['rule' => 'any', 'expiry' => 60]);

        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->workflow('payout')->open([ReviewerTestModel::create()]);

        twoDaysLater();

        expect(Approvals::expire())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired);
    });
});
