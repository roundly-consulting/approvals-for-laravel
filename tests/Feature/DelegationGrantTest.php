<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Events\ApprovalDelegated;
use RoundlyConsulting\Approvals\Exceptions\InvalidDelegationException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/**
 * Regression: the delegation builder used to create the row (and fire ApprovalDelegated)
 * in its constructor, then save from()/until() onto it directly — so listeners saw an
 * open-ended delegation and a window ending before it started was never validated.
 * Delegations are now created by a lazy terminal grant().
 */
afterEach(fn () => CarbonImmutable::setTestNow());

it('writes nothing and fires nothing before grant()', function (): void {
    Event::fake([ApprovalDelegated::class]);

    Approvals::delegations(ReviewerTestModel::create())
        ->to(ReviewerTestModel::create())
        ->from(CarbonImmutable::now())
        ->until(CarbonImmutable::now()->addDay());

    expect(ApprovalDelegation::query()->count())->toBe(0);

    Event::assertNotDispatched(ApprovalDelegated::class);
});

it('announces the delegation with its final window', function (): void {
    CarbonImmutable::setTestNow('2026-09-28 12:00:00');

    $seen = [];
    Event::listen(ApprovalDelegated::class, function (ApprovalDelegated $event) use (&$seen): void {
        $seen[] = [
            $event->delegation->starts_at?->toIso8601String(),
            $event->delegation->ends_at?->toIso8601String(),
        ];
    });

    $from = CarbonImmutable::now()->addHour();
    $until = CarbonImmutable::now()->addDay();

    Approvals::delegations(ReviewerTestModel::create())
        ->to(ReviewerTestModel::create())
        ->from($from)
        ->until($until)
        ->grant();

    expect($seen)->toBe([[$from->toIso8601String(), $until->toIso8601String()]]);
});

it('validates a builder window that ends before it starts', function (): void {
    $pending = Approvals::delegations(ReviewerTestModel::create())
        ->to(ReviewerTestModel::create())
        ->from(CarbonImmutable::now()->addDay())
        ->until(CarbonImmutable::now());

    expect(fn () => $pending->grant())->toThrow(InvalidDelegationException::class, 'before it starts')
        ->and(ApprovalDelegation::query()->count())->toBe(0);
});

it('refuses self-delegation on grant()', function (): void {
    $boss = ReviewerTestModel::create();

    expect(fn () => Approvals::delegations($boss)->to($boss)->grant())
        ->toThrow(InvalidDelegationException::class)
        ->and(ApprovalDelegation::query()->count())->toBe(0);
});

it('validates the window given to the model trait too', function (): void {
    $boss = ReviewerTestModel::create();

    expect(fn () => $boss->delegateApprovalsTo(
        ReviewerTestModel::create(),
        from: CarbonImmutable::now()->addDay(),
        until: CarbonImmutable::now(),
    ))->toThrow(InvalidDelegationException::class);
});
