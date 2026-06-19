<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Approvals\Actions\ExpireApprovalsAction;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Events\ApprovalExpired;
use RoundlyConsulting\Approvals\Models\Approval;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('expires due pending approvals and counts them', function (): void {
    Event::fake();
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    Approval::factory()->pending()->create(['expires_at' => now()->subHour()]);
    Approval::factory()->pending()->create(['expires_at' => now()->subDay()]);
    Approval::factory()->pending()->create(['expires_at' => now()->addDay()]);
    Approval::factory()->pending()->create(['expires_at' => null]);

    $count = app(ExpireApprovalsAction::class)->execute();

    expect($count)->toBe(2)
        ->and(Approval::query()->expired()->count())->toBe(2)
        ->and(Approval::query()->pending()->count())->toBe(2);

    Event::assertDispatched(ApprovalExpired::class, 2);
});

it('accepts an explicit moment', function (): void {
    Approval::factory()->pending()->create(['expires_at' => CarbonImmutable::parse('2026-01-01')]);

    $count = app(ExpireApprovalsAction::class)->execute(CarbonImmutable::parse('2025-12-31'));

    expect($count)->toBe(0);

    $count = app(ExpireApprovalsAction::class)->execute(CarbonImmutable::parse('2026-02-01'));

    expect($count)->toBe(1)
        ->and(Approval::query()->first()->status)->toBe(ApprovalStatus::Expired);
});
