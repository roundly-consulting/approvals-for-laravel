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
