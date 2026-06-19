<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Enums\ApprovalRule;

it('exposes every rule case', function (): void {
    expect(ApprovalRule::Unanimous->value)->toBe('unanimous')
        ->and(ApprovalRule::Quorum->value)->toBe('quorum')
        ->and(ApprovalRule::Any->value)->toBe('any')
        ->and(ApprovalRule::cases())->toHaveCount(3);
});
