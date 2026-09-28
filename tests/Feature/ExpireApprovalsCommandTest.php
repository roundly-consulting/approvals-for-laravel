<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Models\Approval;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('expires due approvals via the command', function (): void {
    CarbonImmutable::setTestNow('2026-06-19 12:00:00');

    Approval::factory()->pending()->create(['expires_at' => now()->subHour()]);

    $this->artisan('approvals:expire')
        ->expectsOutputToContain('Expired 1 approval(s) and request(s).')
        ->assertSuccessful();

    expect(Approval::query()->first()->status)->toBe(ApprovalStatus::Expired);
});

it('reports zero when nothing is due', function (): void {
    $this->artisan('approvals:expire')
        ->expectsOutputToContain('Expired 0 approval(s) and request(s).')
        ->assertSuccessful();
});
