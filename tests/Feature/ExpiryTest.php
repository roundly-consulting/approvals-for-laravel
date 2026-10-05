<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalExpired;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Exceptions\ClosedApprovalRequestException;
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

    it('a rejected ask drops its reply-by deadline and the slot stays usable after it', function (): void {
        $deployment = DeploymentTestModel::create();
        $cfo = ReviewerTestModel::create();

        Approvals::for($deployment)->as($cfo)->expiresIn(3600)->ask();
        $rejection = $cfo->reject($deployment);

        expect($rejection->expires_at)->toBeNull();

        twoDaysLater();

        // The rejection still stands once the ask's deadline has passed.
        expect($cfo->hasRejected($deployment))->toBeTrue();

        // And every later decision in the slot goes through.
        expect($cfo->approve($deployment)->status)->toBe(ApprovalStatus::Approved)
            ->and($cfo->reject($deployment)->status)->toBe(ApprovalStatus::Rejected)
            ->and($cfo->toggleApproval($deployment))->toBeTrue()
            ->and(Approval::query()->live()->sole()->status)->toBe(ApprovalStatus::Approved);
    });

    it('unblocks a slot whose rejection kept an ask deadline that has passed', function (string $next): void {
        $deployment = DeploymentTestModel::create();
        $cfo = ReviewerTestModel::create();

        // A rejection written before the fix: it kept the ask's reply-by deadline.
        $stale = Approval::factory()->rejected()->forActor($cfo)->forApprovable($deployment)->create([
            'expires_at' => CarbonImmutable::now()->addHour(),
        ]);

        twoDaysLater();

        $decision = match ($next) {
            'approve' => $cfo->approve($deployment),
            'reject' => $cfo->reject($deployment),
            'ask' => Approvals::for($deployment)->as($cfo)->ask(),
            'toggle' => $cfo->toggleApproval($deployment),
        };

        expect($decision)->not->toBeFalse()
            ->and($stale->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($stale->fresh()?->live)->toBeNull()
            ->and(Approval::query()->live()->count())->toBe(1);
    })->with(['approve', 'reject', 'ask', 'toggle']);
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

    it('never expires approvals when unset', function (?string $unset): void {
        config()->set('approvals.expiry.default', $unset);

        expect(ReviewerTestModel::create()->approve(DeploymentTestModel::create())->expires_at)->toBeNull();
    })->with(['absent' => null, 'blank' => '', 'whitespace' => '  ']);

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

        expect(fn () => $lead->approve($release))->toThrow(ClosedApprovalRequestException::class, 'is closed (expired)')
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and(Approval::query()->count())->toBe(0);
    });

    it('refuses a decision pinned to an overdue request', function (): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$lead])->expiresIn(60)->open();

        twoDaysLater();

        expect(fn () => Approvals::for($release)->as($lead)->within($request)->approve())
            ->toThrow(ClosedApprovalRequestException::class, 'is closed (expired)')
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

describe('the progress of an overdue request', function (): void {
    it('reports expired, as status() does', function (bool $staged): void {
        $release = ReleaseTestModel::create();
        $lead = ReviewerTestModel::create();

        $builder = Approvals::request($release)->expiresIn(60);

        $staged
            ? $builder->stages([new StageDefinition([$lead], ApprovalRule::Any)])->open()
            : $builder->from([$lead])->open();

        expect(Approvals::progress($release)?->status)->toBe(ApprovalStatus::Pending);

        twoDaysLater();

        expect(Approvals::status($release))->toBe(ApprovalStatus::Expired)
            ->and(Approvals::progress($release)?->status)->toBe(ApprovalStatus::Expired)
            ->and($release->approvalProgress()?->status)->toBe(ApprovalStatus::Expired);
    })->with(['flat' => false, 'staged' => true]);
});

describe('a sweep scoped to one subject type', function (): void {
    afterEach(fn () => Relation::morphMap([], false));

    it('lapses only that type\'s decisions and requests', function (): void {
        $lead = ReviewerTestModel::create();

        $releaseRequest = Approvals::request(ReleaseTestModel::create())->from([$lead])->expiresIn(60)->open();
        $deploymentRequest = Approvals::request(DeploymentTestModel::create())->from([$lead])->expiresIn(60)->open();
        $releaseApproval = Approvals::for(ReleaseTestModel::create())->as($lead)->expiresIn(60)->approve();
        $deploymentAsk = Approvals::for(DeploymentTestModel::create())->as($lead)->expiresIn(60)->ask();

        twoDaysLater();

        expect(Approvals::expire(subjectType: ReleaseTestModel::class))->toBe(2)
            ->and($releaseRequest->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and($releaseApproval->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and($deploymentRequest->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($deploymentAsk->fresh()?->status)->toBe(ApprovalStatus::Pending);

        // Without a type the sweep stays app-wide.
        expect(Approvals::expire())->toBe(2)
            ->and($deploymentRequest->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and($deploymentAsk->fresh()?->status)->toBe(ApprovalStatus::Expired);
    });

    it('matches a morph-mapped type given as its alias or its class', function (): void {
        Relation::morphMap(['release' => ReleaseTestModel::class]);

        $lead = ReviewerTestModel::create();
        Approvals::for(ReleaseTestModel::create())->as($lead)->expiresIn(60)->approve();
        Approvals::for(ReleaseTestModel::create())->as($lead)->expiresIn(60)->approve();
        $kept = Approvals::for(DeploymentTestModel::create())->as($lead)->expiresIn(60)->approve();

        twoDaysLater();

        expect(Approvals::expire(subjectType: 'release'))->toBe(2)
            ->and(Approvals::expire(subjectType: ReleaseTestModel::class))->toBe(0)
            ->and($kept->fresh()?->status)->toBe(ApprovalStatus::Approved);
    });

    it('resolves the morph alias of a class-string', function (): void {
        Relation::morphMap(['release' => ReleaseTestModel::class]);

        Approvals::for(ReleaseTestModel::create())->as(ReviewerTestModel::create())->expiresIn(60)->ask();

        twoDaysLater();

        expect(Approvals::expire(subjectType: ReleaseTestModel::class))->toBe(1);
    });
});
