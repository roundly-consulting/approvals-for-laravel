<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Events\ApprovalRoundOpened;
use RoundlyConsulting\Approvals\Events\ApprovalStageOpened;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/*
|--------------------------------------------------------------------------
| ApprovalRoundOpened
|--------------------------------------------------------------------------
|
| One event per round that opens, flat or staged, after the surrounding commit: a
| staged round fires it before its first ApprovalStageOpened. Advancing a stage and
| replacing approvers do not.
|
*/

/**
 * Record the round and stage events in the order they arrive.
 *
 * @return ArrayObject<int, object>
 */
function recordRoundEvents(): ArrayObject
{
    $heard = new ArrayObject;

    Event::listen(ApprovalRoundOpened::class, static function (ApprovalRoundOpened $event) use ($heard): void {
        $heard[] = $event;
    });
    Event::listen(ApprovalStageOpened::class, static function (ApprovalStageOpened $event) use ($heard): void {
        $heard[] = $event;
    });

    return $heard;
}

/**
 * @param  ArrayObject<int, object>  $heard
 * @return list<ApprovalRoundOpened>
 */
function roundsHeard(ArrayObject $heard): array
{
    return array_values(array_filter($heard->getArrayCopy(), static fn (object $event): bool => $event instanceof ApprovalRoundOpened));
}

it('fires once for a flat round, carrying the round, its subject and every approver once', function (): void {
    $heard = recordRoundEvents();

    [$a, $b, $c] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];
    $release = ReleaseTestModel::create();

    $round = Approvals::request($release)->from([$a, $b, $c, $a])->quorum(2)->open();

    $rounds = roundsHeard($heard);

    expect($rounds)->toHaveCount(1)
        ->and($rounds[0]->round->is($round))->toBeTrue()
        ->and($rounds[0]->subject()?->is($release))->toBeTrue()
        ->and($rounds[0]->stage)->toBeNull()
        ->and(array_map(static fn ($approver) => $approver->getKey(), $rounds[0]->approvers))
        ->toBe([$a->getKey(), $b->getKey(), $c->getKey()]);
});

it('fires once for a flat workflow round', function (): void {
    config()->set('approvals.workflows.payout', ['rule' => 'any']);

    $heard = recordRoundEvents();

    [$a, $b] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    Approvals::request(ReleaseTestModel::create())->workflow('payout')->open([$a, $b]);

    expect(roundsHeard($heard))->toHaveCount(1)
        ->and(roundsHeard($heard)[0]->round->workflow)->toBe('payout')
        ->and(roundsHeard($heard)[0]->approvers)->toHaveCount(2);
});

it('fires once for a staged round, before its first stage opens', function (): void {
    $heard = recordRoundEvents();

    [$eng, $product] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    $release = ReleaseTestModel::create();

    Approvals::request($release)->stages([
        new StageDefinition([$eng], ApprovalRule::Any, name: 'engineering'),
        new StageDefinition([$product], ApprovalRule::Any, name: 'product'),
    ])->open();

    expect($heard)->toHaveCount(2)
        ->and($heard[0])->toBeInstanceOf(ApprovalRoundOpened::class)
        ->and($heard[1])->toBeInstanceOf(ApprovalStageOpened::class)
        ->and($heard[0]->stage?->name)->toBe('engineering')
        ->and(array_map(static fn ($approver) => $approver->getKey(), $heard[0]->approvers))->toBe([$eng->getKey()]);

    // Advancing to the next stage opens a stage, not a round.
    $eng->approve($release);

    expect(roundsHeard($heard))->toHaveCount(1)
        ->and($heard[2])->toBeInstanceOf(ApprovalStageOpened::class)
        ->and($heard[2]->stage->name)->toBe('product');
});

it('fires once for a staged workflow round', function (): void {
    config()->set('approvals.workflows.release', ['stages' => [['rule' => 'any', 'required_approvers' => 1], ['rule' => 'any', 'required_approvers' => 1]]]);

    $heard = recordRoundEvents();

    Approvals::request(ReleaseTestModel::create())->workflow('release')->open([[ReviewerTestModel::create()], [ReviewerTestModel::create()]]);

    expect(roundsHeard($heard))->toHaveCount(1)
        ->and($heard[0])->toBeInstanceOf(ApprovalRoundOpened::class);
});

it('does not fire when approvers are replaced', function (): void {
    [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

    $flat = ReleaseTestModel::create();
    Approvals::request($flat)->from([$a])->open();

    $staged = ReleaseTestModel::create();
    Approvals::request($staged)->stages([new StageDefinition([$b], name: 'one')])->open();

    $heard = recordRoundEvents();

    Approvals::for($flat)->replaceFlatApprovers([$m]);
    Approvals::for($staged)->replaceApprovers('one', [$m]);

    expect(roundsHeard($heard))->toBe([]);
});

it('does not fire when opening throws and writes no round', function (): void {
    $heard = recordRoundEvents();

    $release = ReleaseTestModel::create();
    $a = ReviewerTestModel::create();

    expect(fn () => Approvals::request($release)->from([$a])->quorum(3)->open())
        ->toThrow(InvalidApprovalRequestException::class)
        ->and(fn () => Approvals::request($release)->stages([new StageDefinition([$a], ApprovalRule::Quorum, quorum: 2)])->open())
        ->toThrow(InvalidApprovalRequestException::class)
        ->and(fn () => Approvals::request($release)->excluding([$a])->from([$a])->open())
        ->toThrow(InvalidApprovalRequestException::class);

    expect($heard->getArrayCopy())->toBe([])
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('fires after the surrounding transaction commits', function (): void {
    $heard = recordRoundEvents();

    $a = ReviewerTestModel::create();

    DB::transaction(function () use ($heard, $a): void {
        Approvals::request(ReleaseTestModel::create())->from([$a])->open();
        Approvals::request(ReleaseTestModel::create())->stages([new StageDefinition([$a])])->open();

        expect($heard->getArrayCopy())->toBe([]);
    });

    expect($heard)->toHaveCount(3)
        ->and($heard[0])->toBeInstanceOf(ApprovalRoundOpened::class)
        ->and($heard[1])->toBeInstanceOf(ApprovalRoundOpened::class)
        ->and($heard[2])->toBeInstanceOf(ApprovalStageOpened::class);
});

it('never fires for a transaction that rolls back', function (): void {
    $heard = recordRoundEvents();

    try {
        DB::transaction(function (): void {
            Approvals::request(ReleaseTestModel::create())->stages([new StageDefinition([ReviewerTestModel::create()])])->open();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($heard->getArrayCopy())->toBe([])
        ->and(ApprovalRequest::query()->count())->toBe(0);
});

it('lets the fake assert rounds opened', function (): void {
    $fake = Approvals::fake();

    $fake->assertNoRoundsOpened();

    $release = ReleaseTestModel::create();
    Approvals::request($release)->from([ReviewerTestModel::create()])->open();

    $fake->assertRoundOpened();
    $fake->assertRoundOpened(fn (ApprovalRequest $round): bool => $round->subject_id === $release->getKey());

    expect(fn () => $fake->assertRoundOpened(fn (ApprovalRequest $round): bool => $round->staged === true))
        ->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNoRoundsOpened())->toThrow(AssertionFailedError::class);
});
