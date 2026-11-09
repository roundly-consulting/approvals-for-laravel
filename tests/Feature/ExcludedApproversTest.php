<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\OpenApprovalRequestAction;
use RoundlyConsulting\Approvals\Actions\RequestStagedApprovalAction;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalOperation;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalApproved;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Exceptions\UnauthorizedApprovalException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\Fixtures\AuthorExclusionResolver;
use RoundlyConsulting\Approvals\Tests\Fixtures\InvoiceTestModel;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
|--------------------------------------------------------------------------
| Excluding actors from deciding
|--------------------------------------------------------------------------
|
| Per round (`->excluding([$requester])`) and app-wide (`approvals.exclusions.resolver`):
| an excluded actor can neither decide nor be asked, cannot act as anyone's delegate,
| and no delegate can decide on its behalf.
|
*/

beforeEach(fn () => AuthorExclusionResolver::$calls = []);

describe('per round', function (): void {
    it('stores the excluded actors on the round, each once', function (): void {
        $release = ReleaseTestModel::create();
        [$a, $requester] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $round = Approvals::request($release)->excluding([$requester, $requester])->from([$a])->open();

        expect($round->fresh()?->excludedApprovers())->toHaveCount(1)
            ->and($round->fresh()?->excludedApprovers()[0]->matches($requester))->toBeTrue();
    });

    it('leaves a round opened without exclusions unchanged', function (): void {
        $round = Approvals::request(ReleaseTestModel::create())->from([ReviewerTestModel::create()])->open();

        expect($round->fresh()?->excluded_approvers)->toBeNull()
            ->and($round->fresh()?->excludedApprovers())->toBe([]);
    });

    it('refuses a decision by an excluded actor on an unnamed round', function (string $verb): void {
        $release = ReleaseTestModel::create();
        $requester = ReviewerTestModel::create();

        Approvals::request($release)->excluding([$requester])->any()->open();

        expect(fn () => match ($verb) {
            'approve' => $requester->approve($release),
            'reject' => $requester->reject($release),
            'ask' => Approvals::for($release)->as($requester)->ask(),
            'toggle' => $requester->toggleApproval($release),
        })->toThrow(UnauthorizedApprovalException::class, 'is excluded from deciding')
            ->and(Approval::query()->count())->toBe(0);
    })->with(['approve', 'reject', 'ask', 'toggle']);

    it('still lets everyone else decide', function (): void {
        $release = ReleaseTestModel::create();
        [$requester, $reviewer] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::request($release)->excluding([$requester])->any()->open();

        $reviewer->approve($release);

        expect(Approvals::status($release))->toBe(ApprovalStatus::Approved);
    });

    it('refuses an excluded actor acting as the delegate of a named approver', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $requester] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::delegations($boss)->to($requester)->grant();
        Approvals::request($release)->excluding([$requester])->from([$boss])->open();

        expect(fn () => $requester->approve($release))->toThrow(UnauthorizedApprovalException::class, 'is excluded from deciding')
            ->and(Approval::query()->count())->toBe(0);
    });

    it('refuses a delegate deciding for an excluded approver on an unnamed round', function (): void {
        $release = ReleaseTestModel::create();
        [$requester, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::delegations($requester)->to($deputy)->grant();
        Approvals::request($release)->excluding([$requester])->any()->open();

        expect(fn () => $deputy->approve($release))
            ->toThrow(UnauthorizedApprovalException::class, 'cannot decide on behalf of')
            ->and(Approval::query()->count())->toBe(0);
    });

    it('lets a delegate of an excluded and a named approver decide for the named one', function (): void {
        $release = ReleaseTestModel::create();
        [$boss, $requester, $deputy] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

        Approvals::delegations($boss)->to($deputy)->grant();
        Approvals::delegations($requester)->to($deputy)->grant();

        // Named approvers can only be the boss: the requester is excluded, so it cannot be named.
        Approvals::request($release)->excluding([$requester])->from([$boss])->open();

        $approval = $deputy->approve($release);

        expect($approval->actor_id)->toBe($boss->getKey())
            ->and($approval->decided_by_id)->toBe($deputy->getKey());
    });

    it('refuses a delegate whose only named principal is excluded by the resolver', function (): void {
        config()->set('approvals.exclusions.resolver', AuthorExclusionResolver::class);

        [$author, $deputy, $other] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];
        $invoice = InvoiceTestModel::create();

        // Named before the author is set, so the open-time refusal does not apply.
        Approvals::request($invoice)->from([$author, $other])->open();
        $invoice->update(['author_id' => $author->getKey()]);

        Approvals::delegations($author)->to($deputy)->grant();

        expect(fn () => $deputy->approve($invoice))
            ->toThrow(UnauthorizedApprovalException::class, 'cannot decide on behalf of')
            ->and(fn () => $author->approve($invoice))->toThrow(UnauthorizedApprovalException::class, 'is excluded')
            ->and(Approval::query()->count())->toBe(0);
    });

    it('refuses naming an excluded actor as an approver', function (): void {
        $release = ReleaseTestModel::create();
        $requester = ReviewerTestModel::create();

        expect(fn () => Approvals::request($release)->excluding([$requester])->from([$requester])->open())
            ->toThrow(InvalidApprovalRequestException::class, 'is excluded from deciding')
            ->and(fn () => Approvals::request($release)->excluding([$requester])->stages([
                new StageDefinition([ReviewerTestModel::create()], name: 'one'),
                new StageDefinition([$requester], name: 'two'),
            ])->open())->toThrow(InvalidApprovalRequestException::class, 'is excluded from deciding')
            ->and($release->approvalRequests()->count())->toBe(0);
    });

    it('refuses an unsaved excluded actor', function (): void {
        expect(fn () => Approvals::request(ReleaseTestModel::create())->excluding([new ReviewerTestModel])->any()->open())
            ->toThrow(InvalidApprovalRequestException::class, 'must be saved before it can be excluded');
    });

    it('excludes on staged rounds and on rounds opened from a workflow preset', function (): void {
        config()->set('approvals.workflows.payout', ['rule' => ApprovalRule::Any->value]);
        config()->set('approvals.workflows.release', ['stages' => [['rule' => 'any', 'required_approvers' => 1]]]);

        [$requester, $a] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $staged = ReleaseTestModel::create();
        Approvals::request($staged)->excluding([$requester])->stages([new StageDefinition([], ApprovalRule::Any, requiredApprovers: 1)])->open();

        $flatPreset = ReleaseTestModel::create();
        Approvals::request($flatPreset)->excluding([$requester])->workflow('payout')->open([$a]);

        $stagedPreset = ReleaseTestModel::create();
        Approvals::request($stagedPreset)->workflow('release')->excluding([$requester])->open([[]]);

        foreach ([$staged, $flatPreset, $stagedPreset] as $subject) {
            expect(fn () => $requester->approve($subject))->toThrow(UnauthorizedApprovalException::class, 'is excluded');
        }
    });

    it('excludes through the raw open actions', function (): void {
        [$requester, $a] = [ReviewerTestModel::create(), ReviewerTestModel::create()];

        $flat = ReleaseTestModel::create();
        app(OpenApprovalRequestAction::class)->execute(new ApprovalRequestData($flat, [$a], excluded: [$requester]));

        $staged = ReleaseTestModel::create();
        app(RequestStagedApprovalAction::class)->execute($staged, [new StageDefinition([$a])], excluded: [$requester]);

        expect(fn () => Approvals::for($flat)->as($requester)->ask())->toThrow(UnauthorizedApprovalException::class)
            ->and(fn () => Approvals::for($staged)->as($requester)->ask())->toThrow(UnauthorizedApprovalException::class);
    });

    it('still lets an excluded actor withdraw a decision it holds', function (): void {
        $deployment = DeploymentTestModel::create();
        $actor = ReviewerTestModel::create();

        $actor->approve($deployment);

        config()->set('approvals.exclusions.resolver', AuthorExclusionResolver::class);

        expect($actor->cancelApproval($deployment)?->status)->toBe(ApprovalStatus::Cancelled);
    });

    it('records the exclusions an open carried in the fake', function (): void {
        $fake = Approvals::fake();
        $requester = ReviewerTestModel::create();

        Approvals::request(ReleaseTestModel::create())->excluding([$requester])->any()->open();

        expect($fake->recorded(ApprovalOperation::Open)[0]->context['excluded'])->toBe([$requester]);
    });
});

describe('the configured resolver', function (): void {
    beforeEach(fn () => config()->set('approvals.exclusions.resolver', AuthorExclusionResolver::class));

    it('refuses the author of the approvable, on a round and standalone', function (): void {
        $author = ReviewerTestModel::create();
        $reviewer = ReviewerTestModel::create();

        $standalone = InvoiceTestModel::create(['author_id' => $author->getKey()]);
        $inRound = InvoiceTestModel::create(['author_id' => $author->getKey()]);
        $round = Approvals::request($inRound)->from([$reviewer])->open();

        Event::fake([ApprovalApproved::class]);

        expect(fn () => $author->approve($standalone))->toThrow(UnauthorizedApprovalException::class, 'is excluded')
            ->and(fn () => $author->approve($inRound))->toThrow(UnauthorizedApprovalException::class, 'is excluded')
            ->and($reviewer->approve($inRound)->status)->toBe(ApprovalStatus::Approved);

        Event::assertDispatchedTimes(ApprovalApproved::class, 1);

        $request = collect(AuthorExclusionResolver::$calls)->first(fn (array $call): bool => $call[1] !== null)[1] ?? null;

        expect($request?->is($round))->toBeTrue();
    });

    it('refuses naming the author when a round opens', function (): void {
        $author = ReviewerTestModel::create();
        $invoice = InvoiceTestModel::create(['author_id' => $author->getKey()]);

        expect(fn () => Approvals::request($invoice)->from([$author, ReviewerTestModel::create()])->open())
            ->toThrow(InvalidApprovalRequestException::class, 'cannot be named as an approver')
            ->and(AuthorExclusionResolver::$calls[0][1])->toBeNull();
    });

    it('leaves approvables it names nobody for alone', function (): void {
        $actor = ReviewerTestModel::create();

        expect($actor->approve(DeploymentTestModel::create())->status)->toBe(ApprovalStatus::Approved)
            ->and($actor->approve(InvoiceTestModel::create())->status)->toBe(ApprovalStatus::Approved);
    });

    it('treats a blank resolver as none', function (mixed $value): void {
        config()->set('approvals.exclusions.resolver', $value);

        $author = ReviewerTestModel::create();
        $invoice = InvoiceTestModel::create(['author_id' => $author->getKey()]);

        expect($author->approve($invoice)->status)->toBe(ApprovalStatus::Approved);
    })->with(['null' => null, 'blank' => '  ']);

    it('throws on a resolver that is not an implementation, naming the key', function (mixed $value): void {
        config()->set('approvals.exclusions.resolver', $value);

        expect(fn () => ReviewerTestModel::create()->approve(InvoiceTestModel::create()))
            ->toThrow(InvalidConfigurationException::class, 'approvals.exclusions.resolver');
    })->with(['class' => ReviewerTestModel::class, 'missing' => 'App\\Nope', 'array' => [[]]]);

    it('throws when the container builds something else for the resolver', function (): void {
        app()->bind(AuthorExclusionResolver::class, fn (): stdClass => new stdClass);

        expect(fn () => ReviewerTestModel::create()->approve(InvoiceTestModel::create()))
            ->toThrow(InvalidConfigurationException::class, 'approvals.exclusions.resolver');
    });
});
