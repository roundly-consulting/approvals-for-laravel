<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Builders\PendingApproval;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRejected;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\IncompletePendingApprovalException;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| reject(), cancel() and close() refuse the settings they have no place for
|--------------------------------------------------------------------------
|
| They used to drop them silently. A rejection has no expiry; a withdrawal records no
| decision, so it has no weight or expiry; closing a round records no decision and
| involves no actor. Each call now throws InvalidApprovalRequestException before
| anything is written, under the fake as well. The settings that have a place still
| apply, and a bare call works exactly as before. Toggling off keeps dropping weight()
| and the expiry, as documented (ToggleTest).
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

// Typed as Closure in the tests, so Pest hands each one over rather than calling it.
dataset('expiries', [
    'expiresIn()' => [fn (PendingApproval $pending): PendingApproval => $pending->expiresIn(3600)],
    'expiringAt()' => [fn (PendingApproval $pending): PendingApproval => $pending->expiringAt(CarbonImmutable::now()->addWeek())],
]);

describe('reject()', function (): void {
    it('refuses an expiry: a rejection has none', function (Closure $expiry): void {
        Event::fake([ApprovalRejected::class, ApprovalStatusChanged::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        expect(fn () => $expiry(Approvals::for($deployment)->as($reviewer))->reject())
            ->toThrow(InvalidApprovalRequestException::class, 'expiresIn() / expiringAt() cannot be set before it')
            ->and(Approval::query()->withTrashed()->count())->toBe(0);

        Event::assertNotDispatched(ApprovalRejected::class);
        Event::assertNotDispatched(ApprovalStatusChanged::class);
    })->with('expiries');

    it('refuses an expiry with the settings it takes, in a pinned round', function (Closure $expiry): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice])->open();

        expect(fn () => $expiry(Approvals::for($release)->as($alice)->within($round)->because('Needs tests')->weight(2))->reject())
            ->toThrow(InvalidApprovalRequestException::class, 'reject() records a rejection, which has no expiry')
            ->and(Approval::query()->withTrashed()->count())->toBe(0)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with('expiries');

    it('leaves the ask it would answer pending', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice])->open();
        $ask = Approvals::for($release)->as($alice)->because('Please review')->expiresIn(60)->ask();

        expect(fn () => Approvals::for($release)->as($alice)->expiresIn(3600)->reject())
            ->toThrow(InvalidApprovalRequestException::class)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($ask->fresh()?->reason)->toBe('Please review')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    it('leaves the approval it would supersede counting', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();
        $approval = Approvals::for($deployment)->as($reviewer)->approve();

        expect(fn () => Approvals::for($deployment)->as($reviewer)->expiresIn(3600)->reject())
            ->toThrow(InvalidApprovalRequestException::class)
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and(Approval::query()->withTrashed()->count())->toBe(1);
    });

    it('still takes because(), weight() and within()', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice])->open();

        $rejection = Approvals::for($release)->as($alice)->within($round)->because('Needs tests')->weight(2)->reject();

        expect($rejection->status)->toBe(ApprovalStatus::Rejected)
            ->and($rejection->reason)->toBe('Needs tests')
            ->and($rejection->weight)->toBe(2)
            ->and($rejection->expires_at)->toBeNull()
            ->and($rejection->approval_request_id)->toBe($round->getKey());
    });

    it('is unchanged without settings', function (): void {
        Event::fake([ApprovalRejected::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $rejection = Approvals::for($deployment)->as($reviewer)->reject();

        expect($rejection->status)->toBe(ApprovalStatus::Rejected)
            ->and($rejection->reason)->toBeNull()
            ->and($rejection->weight)->toBe(1)
            ->and($rejection->expires_at)->toBeNull();

        Event::assertDispatchedTimes(ApprovalRejected::class, 1);
    });
});

describe('cancel()', function (): void {
    it('refuses weight(): a withdrawal records no decision', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();
        $approval = Approvals::for($deployment)->as($reviewer)->approve();

        Event::fake([ApprovalCancelled::class, ApprovalStatusChanged::class]);

        expect(fn () => Approvals::for($deployment)->as($reviewer)->weight(2)->cancel())
            ->toThrow(InvalidApprovalRequestException::class, 'cancel() withdraws a decision and records none, so [weight()] cannot be set before it')
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved);

        Event::assertNotDispatched(ApprovalCancelled::class);
        Event::assertNotDispatched(ApprovalStatusChanged::class);
    });

    it('refuses an expiry', function (Closure $expiry): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();
        $approval = Approvals::for($deployment)->as($reviewer)->approve();

        expect(fn () => $expiry(Approvals::for($deployment)->as($reviewer))->cancel())
            ->toThrow(InvalidApprovalRequestException::class, 'so [expiresIn() / expiringAt()] cannot be set before it')
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->fresh()?->expires_at)->toBeNull();
    })->with('expiries');

    it('names both when both were set, next to the settings it takes', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice])->open();
        $ask = Approvals::for($release)->as($alice)->ask();

        expect(fn () => Approvals::for($release)->as($alice)->within($round)->because('Withdrawn')->weight(0)->expiresIn(60)->cancel())
            ->toThrow(InvalidApprovalRequestException::class, 'so [weight(), expiresIn() / expiringAt()] cannot be set before it')
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($ask->fresh()?->reason)->toBeNull();
    });

    it('refuses them even when there is nothing to withdraw', function (): void {
        expect(fn () => Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())->weight(2)->cancel())
            ->toThrow(InvalidApprovalRequestException::class, 'cancel() withdraws a decision');
    });

    it('still takes because() and within()', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice, ReviewerTestModel::create()])->open();
        Approvals::for($release)->as($alice)->approve();

        $withdrawn = Approvals::for($release)->as($alice)->within($round)->because('Changed my mind')->cancel();

        expect($withdrawn?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn?->reason)->toBe('Changed my mind');
    });

    it('is unchanged without settings, through the facade and the trait', function (): void {
        $deployment = DeploymentTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::for($deployment)->as($alice)->approve();
        Approvals::for($deployment)->as($bob)->approve();

        expect(Approvals::for($deployment)->as($alice)->cancel()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($bob->cancelApproval($deployment, 'Not yet')?->reason)->toBe('Not yet')
            ->and(Approvals::for($deployment)->as($alice)->cancel())->toBeNull();
    });

    it('still checks the actor first', function (): void {
        expect(fn () => Approvals::for(DeploymentTestModel::create())->weight(2)->cancel())
            ->toThrow(IncompletePendingApprovalException::class);
    });
});

describe('close()', function (): void {
    it('refuses as(): closing a round involves no actor', function (): void {
        Event::fake([ApprovalCancelled::class, ApprovalRequestResolved::class, ApprovalStatusChanged::class]);

        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice])->open();
        $ask = Approvals::for($release)->as($alice)->ask();

        expect(fn () => Approvals::for($release)->as($alice)->close())
            ->toThrow(InvalidApprovalRequestException::class, 'so [as()] cannot be set before it')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($round->fresh()?->resolved_at)->toBeNull()
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Pending);

        Event::assertNotDispatched(ApprovalCancelled::class);
        Event::assertNotDispatched(ApprovalRequestResolved::class);
        Event::assertNotDispatched(ApprovalStatusChanged::class);
    });

    it('refuses as() made first, through Approvals::as()', function (): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::as(ReviewerTestModel::create())->for($release)->close())
            ->toThrow(InvalidApprovalRequestException::class, 'no authorization gate runs')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    it('refuses weight()', function (): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->weight(2)->close())
            ->toThrow(InvalidApprovalRequestException::class, 'so [weight()] cannot be set before it')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    });

    it('refuses an expiry', function (Closure $expiry): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => $expiry(Approvals::for($release))->close())
            ->toThrow(InvalidApprovalRequestException::class, 'so [expiresIn() / expiringAt()] cannot be set before it')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($round->fresh()?->expires_at)->toBeNull();
    })->with('expiries');

    it('names every refused setting, for a pinned round and either outcome', function (ApprovalStatus $outcome): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice])->open();

        expect(fn () => Approvals::for($release)->within($round)->as($alice)->weight(3)->expiresIn(60)->close($outcome))
            ->toThrow(InvalidApprovalRequestException::class, 'so [as(), weight(), expiresIn() / expiringAt()] cannot be set before it')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with([ApprovalStatus::Cancelled, ApprovalStatus::Expired]);

    it('refuses them even when no round is open', function (): void {
        expect(fn () => Approvals::for(ReleaseTestModel::create())->weight(1)->close())
            ->toThrow(InvalidApprovalRequestException::class, 'close() closes a round from outside');
    });

    it('still refuses because() first, with its own message', function (): void {
        $release = ReleaseTestModel::create();
        Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->as(ReviewerTestModel::create())->weight(2)->because('Withdrawn')->close())
            ->toThrow(InvalidApprovalRequestException::class, 'close() records no reason');
    });

    it('still checks the approvable first', function (): void {
        expect(fn () => Approvals::as(ReviewerTestModel::create())->weight(2)->close())
            ->toThrow(IncompletePendingApprovalException::class);
    });

    it('is unchanged without settings, and with within() or because(null)', function (): void {
        $release = ReleaseTestModel::create();
        $approvers = [ReviewerTestModel::create()];

        $first = Approvals::request($release)->from($approvers)->open();

        expect(Approvals::for($release)->close())->toBe(1)
            ->and($first->fresh()?->status)->toBe(ApprovalStatus::Cancelled);

        $second = Approvals::request($release)->from($approvers)->open();

        expect(Approvals::for($release)->within($second)->because(null)->close(ApprovalStatus::Expired))->toBe(1)
            ->and($second->fresh()?->status)->toBe(ApprovalStatus::Expired)
            ->and(Approvals::for($release)->close())->toBe(0);
    });
});

describe('under the fake', function (): void {
    it('refuses the same settings, recording and writing nothing', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $round = Approvals::request($release)->from([$alice, ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->as($alice)->expiresIn(60)->reject())
            ->toThrow(InvalidApprovalRequestException::class, 'reject() records a rejection');

        $approval = Approvals::for($release)->as($alice)->approve();

        expect(fn () => Approvals::for($release)->as($alice)->weight(2)->cancel())
            ->toThrow(InvalidApprovalRequestException::class, 'cancel() withdraws a decision')
            ->and(fn () => Approvals::for($release)->as($alice)->close())
            ->toThrow(InvalidApprovalRequestException::class, 'close() closes a round from outside')
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Approved)
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(Approval::query()->withTrashed()->count())->toBe(1);

        $fake->assertNothingRejected();
        $fake->assertNothingCancelled();
        $fake->assertNothingClosed();
    });

    it('records the bare calls as before', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
        Approvals::request($release)->from([$alice, $bob])->open();

        Approvals::for($release)->as($alice)->approve();
        Approvals::for($release)->as($alice)->cancel();
        Approvals::for($release)->as($bob)->because('Needs tests')->weight(2)->reject();

        $fake->assertCancelled($release, by: $alice);
        $fake->assertRejected($release, by: $bob);

        $release2 = ReleaseTestModel::create();
        Approvals::request($release2)->from([$alice])->open();
        Approvals::for($release2)->close();

        $fake->assertClosed($release2, ApprovalStatus::Cancelled);
    });
});
