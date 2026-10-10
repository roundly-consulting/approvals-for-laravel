<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalCancelled;
use RoundlyConsulting\Approvals\Events\ApprovalRequested;
use RoundlyConsulting\Approvals\Events\ApprovalRequestResolved;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| ask() and close() take or refuse the decision builder's settings
|--------------------------------------------------------------------------
|
| ask() used to drop because() and weight() silently, and close() dropped because().
| An ask is a decision row with a reason column, so ask() now records because() on it.
| A pending decision counts towards no threshold and the answer resolves its own
| weight, so ask() refuses weight(). A round has nowhere to keep a reason, so close()
| refuses because(). A bare ask() / close() works exactly as before.
|
*/

afterEach(fn () => CarbonImmutable::setTestNow());

describe('ask()', function (): void {
    it('records because() as the ask\'s reason', function (): void {
        Event::fake([ApprovalRequested::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $ask = Approvals::for($deployment)->as($reviewer)->because('Please check the rollback plan')->ask();

        expect($ask->status)->toBe(ApprovalStatus::Pending)
            ->and(Approval::query()->sole()->reason)->toBe('Please check the rollback plan');

        Event::assertDispatched(
            ApprovalRequested::class,
            fn (ApprovalRequested $event): bool => $event->approval->reason === 'Please check the rollback plan',
        );
    });

    it('records because() next to the expiry', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())
            ->because('By Friday')
            ->expiresIn(3600)
            ->ask();

        $ask = Approval::query()->sole();

        expect($ask->reason)->toBe('By Friday')
            ->and($ask->expires_at?->toDateTimeString())->toBe('2026-10-10 13:00:00');
    });

    it('gives the answer no reason when it gives none, and the answer\'s when it does', function (): void {
        $deployment = DeploymentTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::for($deployment)->as($alice)->because('Please check the rollback plan')->ask();
        Approvals::for($deployment)->as($bob)->because('Please check the rollback plan')->ask();

        $silent = Approvals::for($deployment)->as($alice)->approve();
        $explained = Approvals::for($deployment)->as($bob)->because('Rollback plan is missing')->reject();

        expect($silent->reason)->toBeNull()
            ->and($explained->reason)->toBe('Rollback plan is missing')
            ->and(Approval::query()->count())->toBe(2);
    });

    it('keeps the ask\'s reason when the round closes and retires it', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();

        Approvals::request($release)->from([$alice])->open();
        $ask = Approvals::for($release)->as($alice)->because('Please sign off')->ask();

        Approvals::for($release)->close();

        expect($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->reason)->toBe('Please sign off');
    });

    it('leaves a live decision unchanged, reason included, as it does the expiry', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $approval = Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();

        $asked = Approvals::for($deployment)->as($reviewer)->because('Look again')->expiresIn(60)->ask();

        expect($asked->is($approval))->toBeTrue()
            ->and($asked->fresh()?->reason)->toBe('Ship it')
            ->and($asked->fresh()?->expires_at)->toBeNull()
            ->and(Approval::query()->count())->toBe(1);
    });

    it('refuses weight(): a pending decision carries none', function (): void {
        Event::fake([ApprovalRequested::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        expect(fn () => Approvals::for($deployment)->as($reviewer)->weight(3)->ask())
            ->toThrow(InvalidApprovalRequestException::class, 'weight() cannot be set before it')
            ->and(Approval::query()->withTrashed()->count())->toBe(0);

        Event::assertNotDispatched(ApprovalRequested::class);
    });

    it('refuses weight() whatever the value, and with other settings', function (int $weight): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();

        Approvals::request($release)->from([$alice])->open();

        expect(fn () => Approvals::for($release)->as($alice)->because('Please sign off')->weight($weight)->expiresIn(60)->ask())
            ->toThrow(InvalidApprovalRequestException::class, 'ask() records a pending decision')
            ->and(Approval::query()->withTrashed()->count())->toBe(0);
    })->with([0, 1, 5]);

    it('is unchanged without settings', function (): void {
        Event::fake([ApprovalRequested::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $ask = Approvals::for($deployment)->as($reviewer)->ask();

        $stored = Approval::query()->sole();

        expect($ask->is($stored))->toBeTrue()
            ->and($stored->status)->toBe(ApprovalStatus::Pending)
            ->and($stored->reason)->toBeNull()
            ->and($stored->weight)->toBe(1)
            ->and($stored->expires_at)->toBeNull()
            ->and($stored->approval_request_id)->toBeNull();

        Event::assertDispatchedTimes(ApprovalRequested::class, 1);
    });

    it('takes a null reason as no reason', function (): void {
        Approvals::for(DeploymentTestModel::create())->as(ReviewerTestModel::create())->because(null)->ask();

        expect(Approval::query()->sole()->reason)->toBeNull();
    });
});

describe('close()', function (): void {
    it('refuses because(): a round has nowhere to keep a reason', function (): void {
        Event::fake([ApprovalCancelled::class, ApprovalRequestResolved::class, ApprovalStatusChanged::class]);

        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$alice])->open();
        $ask = Approvals::for($release)->as($alice)->ask();

        expect(fn () => Approvals::for($release)->because('Purchase withdrawn')->close())
            ->toThrow(InvalidApprovalRequestException::class, 'because() cannot be set before it')
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($request->fresh()?->resolved_at)->toBeNull()
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and($ask->fresh()?->reason)->toBeNull();

        Event::assertNotDispatched(ApprovalCancelled::class);
        Event::assertNotDispatched(ApprovalRequestResolved::class);
        Event::assertNotDispatched(ApprovalStatusChanged::class);
    });

    it('refuses because() for a pinned round and for either outcome', function (ApprovalStatus $outcome): void {
        $release = ReleaseTestModel::create();
        $round = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(fn () => Approvals::for($release)->within($round)->because('Purchase withdrawn')->close($outcome))
            ->toThrow(InvalidApprovalRequestException::class, 'close() records no reason')
            ->and($round->fresh()?->status)->toBe(ApprovalStatus::Pending);
    })->with([ApprovalStatus::Cancelled, ApprovalStatus::Expired]);

    it('refuses because() even when no round is open', function (): void {
        $release = ReleaseTestModel::create();

        expect(fn () => Approvals::for($release)->because('Purchase withdrawn')->close())
            ->toThrow(InvalidApprovalRequestException::class, 'close() records no reason');
    });

    it('closes with a null reason, which is no reason', function (): void {
        $release = ReleaseTestModel::create();
        $request = Approvals::request($release)->from([ReviewerTestModel::create()])->open();

        expect(Approvals::for($release)->because(null)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    });

    it('is unchanged without settings: the asks it retires keep their own reason', function (): void {
        $release = ReleaseTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$alice, $bob])->open();
        $ask = Approvals::for($release)->as($alice)->ask();

        expect(Approvals::for($release)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->reason)->toBeNull();
    });

    it('still ignores the actor, as it always has', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $request = Approvals::request($release)->from([$alice])->open();

        expect(Approvals::for($release)->as($alice)->close())->toBe(1)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Cancelled);
    });
});

describe('under the fake', function (): void {
    it('records the weight and the expiry of each decision', function (): void {
        CarbonImmutable::setTestNow('2026-10-10 12:00:00');

        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        [$lead, $qa, $ops] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::for($release)->as($lead)->because('Ship it')->weight(3)->expiresIn(3600)->approve();
        Approvals::for($release)->as($qa)->weight(2)->reject();
        Approvals::for($release)->as($ops)->expiringAt(CarbonImmutable::parse('2026-10-17 12:00:00'))->ask();

        $approve = $fake->recorded(ApprovalOperation::Approve)[0]->context;
        $reject = $fake->recorded(ApprovalOperation::Reject)[0]->context;
        $ask = $fake->recorded(ApprovalOperation::Ask)[0]->context;

        expect($approve['reason'])->toBe('Ship it')
            ->and($approve['weight'])->toBe(3)
            ->and($approve['expires_at']?->toDateTimeString())->toBe('2026-10-10 13:00:00')
            ->and($reject['weight'])->toBe(2)
            ->and($reject['expires_at'])->toBeNull()
            ->and($ask['weight'])->toBeNull()
            ->and($ask['expires_at']?->toDateTimeString())->toBe('2026-10-17 12:00:00');
    });

    it('records no weight and no expiry for a bare decision', function (): void {
        $fake = Approvals::fake();

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->toggle();
        Approvals::for($deployment)->as($reviewer)->toggle();

        foreach ($fake->recorded(ApprovalOperation::Toggle) as $recorded) {
            expect($recorded->context)->toHaveKeys(['actor', 'approvable', 'request', 'reason', 'weight', 'expires_at'])
                ->and($recorded->context['weight'])->toBeNull()
                ->and($recorded->context['expires_at'])->toBeNull();
        }
    });

    it('records the ask\'s reason, and the ask carries it', function (): void {
        $fake = Approvals::fake();

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $ask = Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();

        expect($ask->reason)->toBe('Please review')
            ->and($fake->recorded(ApprovalOperation::Ask)[0]->context['reason'])->toBe('Please review');

        $fake->assertAsked($deployment, $reviewer);
    });

    it('refuses the same settings, recording nothing', function (): void {
        $fake = Approvals::fake();

        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();
        $request = Approvals::request($release)->from([$alice])->open();

        expect(fn () => Approvals::for($release)->as($alice)->weight(2)->ask())
            ->toThrow(InvalidApprovalRequestException::class)
            ->and(fn () => Approvals::for($release)->because('Withdrawn')->close())
            ->toThrow(InvalidApprovalRequestException::class)
            ->and($request->fresh()?->status)->toBe(ApprovalStatus::Pending)
            ->and(Approval::query()->withTrashed()->count())->toBe(0);

        $fake->assertNothingAsked();
        $fake->assertNothingClosed();
    });
});
