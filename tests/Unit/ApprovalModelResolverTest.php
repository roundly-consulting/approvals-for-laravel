<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;

it('resolves the default approval model', function (): void {
    expect(ApprovalModelResolver::class())->toBe(Approval::class);
});

it('resolves a custom model extending the base approval', function (): void {
    $custom = new class extends Approval {};

    config()->set('approvals.model', $custom::class);

    expect(ApprovalModelResolver::class())->toBe($custom::class);
});

it('rejects a model that does not extend the base approval', function (): void {
    config()->set('approvals.model', stdClass::class);

    ApprovalModelResolver::class();
})->throws(InvalidArgumentException::class);

it('rejects a non-string model configuration', function (): void {
    config()->set('approvals.model', 123);

    ApprovalModelResolver::class();
})->throws(InvalidArgumentException::class);
