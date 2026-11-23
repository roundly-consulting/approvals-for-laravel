<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Approvals\Actions\DelegateApprovalsAction;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidDelegationException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Tests\Fixtures\TeamScopeResolver;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Approvals\Tests\TeamTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
|--------------------------------------------------------------------------
| Scoped delegations
|--------------------------------------------------------------------------
|
| `->within($team)` limits a delegation to one context. With
| `approvals.delegations.scope_resolver` set, a round counts the unscoped delegations
| plus those limited to the scope the resolver names for it.
|
*/

beforeEach(function (): void {
    TeamScopeResolver::$teams = [];
    config()->set('approvals.delegations.scope_resolver', TeamScopeResolver::class);
});

/**
 * A release placed in `$team`, with a flat round naming `$approver`.
 */
function scopedRound(ReviewerTestModel $approver, ?TeamTestModel $team): ReleaseTestModel
{
    $release = ReleaseTestModel::create();

    if ($team instanceof TeamTestModel) {
        TeamScopeResolver::place($release, $team);
    }

    Approvals::request($release)->from([$approver])->open();

    return $release;
}

it('stores the scope on the delegation', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    $team = TeamTestModel::create();

    $delegation = Approvals::delegations($boss)->to($deputy)->within($team)->grant();

    expect($delegation->fresh()->scope_type)->toBe($team->getMorphClass())
        ->and((string) $delegation->fresh()->scope_id)->toBe((string) $team->getKey())
        ->and($delegation->fresh()->scope?->is($team))->toBeTrue()
        ->and($delegation->isScoped())->toBeTrue();
});

it('lets a scoped delegate decide a round in its scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    $teamA = TeamTestModel::create();

    $boss->delegateApprovalsTo($deputy, scope: $teamA);
    $release = scopedRound($boss, $teamA);

    $approval = $deputy->approve($release);

    expect($approval->actor_id)->toBe($boss->getKey())
        ->and($approval->decided_by_id)->toBe($deputy->getKey())
        ->and(Approvals::status($release))->toBe(ApprovalStatus::Approved);
});

it('refuses a scoped delegate in a round of another scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();
    $release = scopedRound($boss, $teamB);

    expect(fn () => $deputy->approve($release))->toThrow(UnauthorizedApprovalException::class)
        ->and(fn () => $deputy->reject($release))->toThrow(UnauthorizedApprovalException::class);
});

it('refuses a scoped delegate in a round that belongs to no scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within(TeamTestModel::create())->grant();
    $release = scopedRound($boss, null);

    expect(fn () => $deputy->approve($release))->toThrow(UnauthorizedApprovalException::class);
});

it('refuses a scoped delegate on a staged round of another scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();

    $release = ReleaseTestModel::create();
    TeamScopeResolver::place($release, $teamB);
    Approvals::request($release)->stages([new StageDefinition([$boss], ApprovalRule::Unanimous)])->open();

    expect(fn () => $deputy->approve($release))->toThrow(UnauthorizedApprovalException::class);
});

it('keeps an unscoped delegation valid in every scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    $boss->delegateApprovalsTo($deputy);

    foreach ([$teamA, $teamB, null] as $team) {
        $release = scopedRound($boss, $team);

        expect($deputy->approve($release)->actor_id)->toBe($boss->getKey());
    }
});

it('makes a scoped delegate decide as itself on an open round of another scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();

    $release = ReleaseTestModel::create();
    TeamScopeResolver::place($release, $teamB);
    Approvals::request($release)->quorum(1)->open();

    // A round without names lets anyone decide: the deputy counts, but as itself.
    $approval = $deputy->approve($release);

    expect($approval->actor_id)->toBe($deputy->getKey())
        ->and($approval->wasDelegated())->toBeFalse();
});

it('changes nothing without a resolver', function (): void {
    config()->set('approvals.delegations.scope_resolver', null);

    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();
    $release = scopedRound($boss, $teamB);

    expect($deputy->approve($release)->actor_id)->toBe($boss->getKey());
});

it('lets a delegate withdraw only what it may still decide in the scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();

    $release = ReleaseTestModel::create();
    TeamScopeResolver::place($release, $teamA);
    Approvals::request($release)->from([$boss, ReviewerTestModel::create()])->open();

    $deputy->approve($release);

    // The round moves to team B: the deputy no longer speaks for the boss there.
    TeamScopeResolver::place($release, $teamB);

    expect($deputy->cancelApproval($release))->toBeNull();

    TeamScopeResolver::place($release, $teamA);

    expect($deputy->cancelApproval($release)?->status)->toBe(ApprovalStatus::Cancelled);
});

it('ignores out-of-scope delegates when rechecking the threshold', function (): void {
    config()->set('approvals.authorization.enabled', true);

    [$blocked, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Gate::define('decide-approval', fn (ReviewerTestModel $user): bool => ! $user->is($blocked));

    Approvals::delegations($blocked)->to($deputy)->within($teamA)->grant();

    $elsewhere = scopedRound($blocked, $teamB);
    $here = scopedRound($blocked, $teamA);

    expect(Approvals::for($elsewhere)->recheckThreshold()?->reachable)->toBeFalse()
        ->and(Approvals::for($here)->recheckThreshold()?->reachable)->toBeTrue();
});

it('limits delegationFor() and active() to a scope when given one', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    $scoped = Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();

    expect(Approvals::delegationFor($deputy)?->is($scoped))->toBeTrue()
        ->and(Approvals::delegationFor($deputy, scope: $teamA)?->is($scoped))->toBeTrue()
        ->and(Approvals::delegationFor($deputy, scope: $teamB))->toBeNull()
        ->and(Approvals::delegations($boss)->active())->toHaveCount(1)
        ->and(Approvals::delegations($boss)->active(scope: $teamA))->toHaveCount(1)
        ->and(Approvals::delegations($boss)->active(scope: $teamB))->toHaveCount(0);

    $everywhere = Approvals::delegations($boss)->to(ReviewerTestModel::create())->grant();

    expect(Approvals::delegations($boss)->active(scope: $teamB)->pluck('id')->all())->toBe([$everywhere->getKey()]);
});

it('filters with the within() query scope', function (): void {
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    $everywhere = ApprovalDelegation::factory()->create();
    $inA = ApprovalDelegation::factory()->scoped($teamA)->create();
    ApprovalDelegation::factory()->scoped($teamB)->create();

    expect(ApprovalDelegation::query()->within($teamA)->orderBy('id')->pluck('id')->all())
        ->toBe([$everywhere->getKey(), $inA->getKey()])
        ->and(ApprovalDelegation::query()->within(null)->pluck('id')->all())->toBe([$everywhere->getKey()])
        ->and($inA->countsWithin($teamA))->toBeTrue()
        ->and($inA->countsWithin($teamB))->toBeFalse()
        ->and($inA->countsWithin(null))->toBeFalse()
        ->and($everywhere->countsWithin($teamB))->toBeTrue();
});

it('refuses an unsaved scope', function (): void {
    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

    expect(fn () => app(DelegateApprovalsAction::class)->execute($boss, $deputy, scope: new TeamTestModel))
        ->toThrow(InvalidDelegationException::class, 'unsaved');
});

it('refuses a resolver that is not a ResolvesDelegationScope', function (): void {
    config()->set('approvals.delegations.scope_resolver', stdClass::class);

    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    Approvals::delegations($boss)->to($deputy)->grant();
    $release = scopedRound($boss, null);

    expect(fn () => $deputy->approve($release))->toThrow(InvalidConfigurationException::class, 'approvals.delegations.scope_resolver');
});

it('records the scope in the fake', function (): void {
    $fake = Approvals::fake();

    [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
    [$teamA, $teamB] = [TeamTestModel::create(), TeamTestModel::create()];

    Approvals::delegations($boss)->to($deputy)->within($teamA)->grant();

    $fake->assertDelegatedWithin($boss, $teamA);
    $fake->assertDelegatedWithin($boss, $teamA, $deputy);

    expect(fn () => $fake->assertDelegatedWithin($boss, $teamB))->toThrow(AssertionFailedError::class)
        ->and($fake->recorded()[0]->model('scope')?->is($teamA))->toBeTrue();
});

it('adds the scope columns without touching existing ones', function (): void {
    $migration = file_get_contents(__DIR__.'/../../database/migrations/0010_add_scope_to_approval_delegations_table.php');

    // Additive only: a Schema::table with new nullable columns and an index — nothing
    // dropped, renamed or changed.
    expect($migration)->toContain("Schema::table('approval_delegations'")
        ->toContain("morphKey('scope', \$keyType, nullable: true)")
        ->not->toContain('dropColumn')
        ->not->toContain('renameColumn')
        ->not->toContain('->change()')
        ->not->toContain('Schema::create')
        ->not->toContain('Schema::drop')
        ->and(Schema::hasColumns('approval_delegations', ['scope_type', 'scope_id']))->toBeTrue()
        ->and(collect(Schema::getIndexes('approval_delegations'))->firstWhere('name', 'approval_delegations_delegate_scope_index')['columns'] ?? null)
        ->toBe(['delegate_id', 'delegate_type', 'scope_id', 'scope_type']);
});

it('keeps delegating without the 2.4.0 columns until a delegation is scoped', function (): void {
    config()->set('approvals.delegations.scope_resolver', null);

    Schema::table('approval_delegations', function (Blueprint $table): void {
        $table->dropIndex('approval_delegations_delegate_scope_index');
        $table->dropMorphs('scope');
    });

    Model::preventAccessingMissingAttributes();

    try {
        [$boss, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];
        $boss->delegateApprovalsTo($deputy);
        $release = scopedRound($boss, null);

        expect($deputy->approve($release)->actor_id)->toBe($boss->getKey())
            ->and(Approvals::delegationFor($deputy))->not->toBeNull()
            ->and(Approvals::delegations($boss)->active())->toHaveCount(1);
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});
