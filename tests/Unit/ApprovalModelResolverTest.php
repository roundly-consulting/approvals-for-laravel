<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalModelException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;

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
})->throws(InvalidApprovalModelException::class);

it('rejects a non-string model configuration', function (): void {
    config()->set('approvals.model', 123);

    ApprovalModelResolver::class();
})->throws(InvalidApprovalModelException::class);

it('resolves the default approval model when the configuration is blank', function (string $blank): void {
    config()->set('approvals.model', $blank);

    expect(ApprovalModelResolver::class())->toBe(Approval::class);
})->with(['blank' => '', 'whitespace' => '  ']);

it('rejects a null model configuration', function (): void {
    config()->set('approvals.model', null);

    ApprovalModelResolver::class();
})->throws(InvalidApprovalModelException::class);

it('rejects an eloquent model that is not an approval', function (): void {
    // The toolkit's resolver only validates "is a model" — the package narrows
    // that to its own base class, and the failure keeps the package's exception.
    config()->set('approvals.model', ActorTestModel::class);

    ApprovalModelResolver::class();
})->throws(InvalidApprovalModelException::class);
