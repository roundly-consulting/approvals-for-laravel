<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalRule;

it('exposes every rule case', function (): void {
    expect(ApprovalRule::Unanimous->value)->toBe('unanimous')
        ->and(ApprovalRule::Quorum->value)->toBe('quorum')
        ->and(ApprovalRule::Any->value)->toBe('any')
        ->and(ApprovalRule::Weighted->value)->toBe('weighted')
        ->and(ApprovalRule::cases())->toHaveCount(4);
});

it('flags weighted-resolving rules', function (): void {
    expect(ApprovalRule::Weighted->isWeighted())->toBeTrue()
        ->and(ApprovalRule::Quorum->isWeighted())->toBeTrue()
        ->and(ApprovalRule::Unanimous->isWeighted())->toBeFalse()
        ->and(ApprovalRule::Any->isWeighted())->toBeFalse();
});

it('exposes the backed values via the helpers trait', function (): void {
    expect(ApprovalRule::values()->all())
        ->toBe(['unanimous', 'quorum', 'any', 'weighted']);
});

it('builds headline labels, options and a validation rule', function (): void {
    expect(ApprovalRule::labels()->all())
        ->toBe(['Unanimous', 'Quorum', 'Any', 'Weighted'])
        ->and(ApprovalRule::options())->toHaveCount(4)
        ->and(ApprovalRule::validationRule())->toBe('in:unanimous,quorum,any,weighted');
});

it('resolves cases by label and name', function (): void {
    expect(ApprovalRule::tryFromLabel('Weighted'))->toBe(ApprovalRule::Weighted)
        ->and(ApprovalRule::tryFromName('missing'))->toBeNull();
});

it('exposes readable labels', function (): void {
    expect(ApprovalRule::Weighted->readable())->toBe('Weighted')
        ->and(ApprovalRule::Any->label())->toBe('Any');
});
