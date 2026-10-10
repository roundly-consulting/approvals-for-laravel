<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Events\ApprovalRejected;
use RoundlyConsulting\Approvals\Events\ApprovalStatusChanged;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| An answer records its own reason, never the asker's
|--------------------------------------------------------------------------
|
| An answer decides the ask's row in place. It used to keep the ask's reason when it
| gave none of its own, so an approval given without a reason read "Please review…".
| approve() and reject() now store the answer's reason as given: none clears the
| ask's. Every other path is unchanged: a withdrawal (cancel(), toggling off, a
| superseded decision, an ask retired when its round closes) keeps the decision's own
| reason, and repeating a decision the actor already holds changes nothing.
|
*/

describe('an answer without a reason', function (): void {
    it('stores no reason when approve() answers an ask', function (): void {
        Event::fake([ApprovalApproved::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $ask = Approvals::for($deployment)->as($reviewer)->because('Please review the rollback plan')->ask();
        $approval = Approvals::for($deployment)->as($reviewer)->approve();

        expect($approval->is($ask))->toBeTrue()
            ->and($approval->status)->toBe(ApprovalStatus::Approved)
            ->and($approval->reason)->toBeNull()
            ->and(Approval::query()->sole()->reason)->toBeNull();

        Event::assertDispatched(ApprovalApproved::class, fn (ApprovalApproved $event): bool => $event->approval->reason === null);
    });

    it('stores no reason when reject() answers an ask', function (): void {
        Event::fake([ApprovalRejected::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review the rollback plan')->ask();
        $rejection = Approvals::for($deployment)->as($reviewer)->reject();

        expect($rejection->status)->toBe(ApprovalStatus::Rejected)
            ->and($rejection->reason)->toBeNull()
            ->and(Approval::query()->sole()->reason)->toBeNull();

        Event::assertDispatched(ApprovalRejected::class, fn (ApprovalRejected $event): bool => $event->approval->reason === null);
    });

    it('stores no reason when because(null) answers an ask', function (string $answer): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();
        Approvals::for($deployment)->as($reviewer)->because(null)->{$answer}();

        expect(Approval::query()->sole()->reason)->toBeNull();
    })->with(['approve', 'reject']);

    it('stores no reason when the ask was answered in a round', function (): void {
        $release = ReleaseTestModel::create();
        [$alice, $bob] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $request = Approvals::request($release)->from([$alice, $bob])->open();
        Approvals::for($release)->as($alice)->because('Please sign off')->ask();

        $answer = Approvals::for($release)->as($alice)->approve();

        expect($answer->approval_request_id)->toBe($request->getKey())
            ->and($answer->reason)->toBeNull();
    });

    it('stores no reason when toggling on answers an ask', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();

        expect(Approvals::for($deployment)->as($reviewer)->toggle())->toBeTrue()
            ->and(Approval::query()->sole()->status)->toBe(ApprovalStatus::Approved)
            ->and(Approval::query()->sole()->reason)->toBeNull();
    });

    it('stores no reason when the trait answers an ask', function (string $answer): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();

        $decision = $reviewer->{$answer}($deployment);

        expect($decision->reason)->toBeNull()
            ->and(Approval::query()->sole()->reason)->toBeNull();
    })->with(['approve', 'reject']);

    it('stores no reason when the model answers a pending row itself', function (string $answer): void {
        $approval = Approval::factory()->pending()->create(['reason' => 'Please review']);

        $approval->{$answer}();

        expect($approval->fresh()?->status)->not->toBe(ApprovalStatus::Pending)
            ->and($approval->fresh()?->reason)->toBeNull();
    })->with(['approve', 'reject']);

    it('records the answer\'s empty reason under the fake as well', function (): void {
        $fake = Approvals::fake();

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();
        $approval = Approvals::for($deployment)->as($reviewer)->approve();

        expect($approval->reason)->toBeNull()
            ->and($fake->recorded(ApprovalOperation::Approve)[0]->context['reason'])->toBeNull();
    });
});

describe('an answer with a reason', function (): void {
    it('stores its own reason in place of the ask\'s', function (string $answer): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();
        $decision = Approvals::for($deployment)->as($reviewer)->because('Rollback plan checked')->{$answer}();

        expect($decision->reason)->toBe('Rollback plan checked')
            ->and(Approval::query()->sole()->reason)->toBe('Rollback plan checked');
    })->with(['approve', 'reject']);
});

describe('paths that keep a decision\'s own reason', function (): void {
    it('leaves a repeated approve() unchanged, reason included', function (): void {
        Event::fake([ApprovalApproved::class, ApprovalStatusChanged::class]);

        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $first = Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();
        $again = Approvals::for($deployment)->as($reviewer)->approve();

        expect($again->is($first))->toBeTrue()
            ->and($again->fresh()?->reason)->toBe('Ship it')
            ->and(Approval::query()->count())->toBe(1);

        Event::assertDispatchedTimes(ApprovalApproved::class, 1);
    });

    it('leaves a repeated reject() unchanged, reason included', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $first = Approvals::for($deployment)->as($reviewer)->because('Needs tests')->reject();
        $again = Approvals::for($deployment)->as($reviewer)->reject();

        expect($again->is($first))->toBeTrue()
            ->and($again->fresh()?->reason)->toBe('Needs tests')
            ->and(Approval::query()->count())->toBe(1);
    });

    it('leaves a repeated approve() in a closed round unchanged, reason included', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();

        $request = Approvals::request($release)->from([$alice])->open();
        $approval = Approvals::for($release)->as($alice)->because('Ship it')->approve();

        expect($request->fresh()?->status)->toBe(ApprovalStatus::Approved);

        $again = Approvals::for($release)->as($alice)->within($request)->approve();

        expect($again->is($approval))->toBeTrue()
            ->and($again->fresh()?->reason)->toBe('Ship it');
    });

    it('keeps the decision\'s own reason when cancel() gives none', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();
        $withdrawn = Approvals::for($deployment)->as($reviewer)->cancel();

        expect($withdrawn?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn?->reason)->toBe('Ship it');
    });

    it('keeps the ask\'s reason when the ask is withdrawn without one', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        Approvals::for($deployment)->as($reviewer)->because('Please review')->ask();
        $withdrawn = Approvals::for($deployment)->as($reviewer)->cancel();

        expect($withdrawn?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($withdrawn?->reason)->toBe('Please review');
    });

    it('keeps a superseded decision\'s reason when the new one gives none', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $approval = Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();
        $rejection = Approvals::for($deployment)->as($reviewer)->reject();

        expect($rejection->is($approval))->toBeFalse()
            ->and($rejection->reason)->toBeNull()
            ->and($approval->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($approval->fresh()?->reason)->toBe('Ship it');
    });

    it('keeps the approval\'s reason when toggling off gives none', function (): void {
        $deployment = DeploymentTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $approval = Approvals::for($deployment)->as($reviewer)->because('Ship it')->approve();

        expect(Approvals::for($deployment)->as($reviewer)->toggle())->toBeFalse()
            ->and(Approval::withTrashed()->findOrFail($approval->getKey())->reason)->toBe('Ship it');
    });

    it('keeps the ask\'s reason when its round closes and retires it', function (): void {
        $release = ReleaseTestModel::create();
        $alice = ReviewerTestModel::create();

        Approvals::request($release)->from([$alice])->open();
        $ask = Approvals::for($release)->as($alice)->because('Please sign off')->ask();

        Approvals::for($release)->close();

        expect($ask->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($ask->fresh()?->reason)->toBe('Please sign off');
    });

    it('keeps the reason when the model cancels a row itself', function (): void {
        $approval = Approval::factory()->approved()->create(['reason' => 'Ship it']);

        $approval->cancel();

        expect($approval->fresh()?->status)->toBe(ApprovalStatus::Cancelled)
            ->and($approval->fresh()?->reason)->toBe('Ship it');
    });
});
