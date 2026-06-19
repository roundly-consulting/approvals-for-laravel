<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;

it('builds an approval request through its factory', function (): void {
    $request = ApprovalRequest::factory()->quorum(2)->create();

    expect($request)->toBeInstanceOf(ApprovalRequest::class)
        ->rule->toBe(ApprovalRule::Quorum)
        ->quorum->toBe(2)
        ->status->toBe(ApprovalStatus::Pending);
});

it('exposes relations', function (): void {
    $request = new ApprovalRequest;

    expect($request->subject())->toBeInstanceOf(MorphTo::class)
        ->and($request->decisions())->toBeInstanceOf(MorphMany::class);
});

it('resolves the default request model', function (): void {
    expect(ApprovalRequestModelResolver::class())->toBe(ApprovalRequest::class);
});

it('rejects an invalid request model', function (): void {
    config()->set('approvals.request_model', stdClass::class);

    ApprovalRequestModelResolver::class();
})->throws(InvalidApprovalModelException::class);

it('rejects a non-string request model', function (): void {
    config()->set('approvals.request_model', 99);

    ApprovalRequestModelResolver::class();
})->throws(InvalidApprovalModelException::class);

it('does not re-resolve a final request', function (): void {
    $request = ApprovalRequest::factory()->any()->create(['status' => ApprovalStatus::Approved]);

    $resolved = $request->resolve();

    expect($resolved->status)->toBe(ApprovalStatus::Approved)
        ->and($resolved->resolved_at)->toBeNull();
});
