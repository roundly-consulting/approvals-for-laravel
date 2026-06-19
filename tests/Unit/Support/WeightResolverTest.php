<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Support\WeightResolver;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Approvals\Tests\WeightedReviewerTestModel;

it('uses an explicit override above all else', function (): void {
    $actor = WeightedReviewerTestModel::create();
    $actor->weight = 9;

    expect(app(WeightResolver::class)->resolve($actor, null, 4))->toBe(4);
});

it('reads the contract weight when present', function (): void {
    $actor = WeightedReviewerTestModel::create();
    $actor->weight = 7;

    expect(app(WeightResolver::class)->resolve($actor))->toBe(7);
});

it('defaults plain actors to weight one', function (): void {
    $actor = ReviewerTestModel::create();

    expect(app(WeightResolver::class)->resolve($actor))->toBe(1);
});

it('floors a negative override at zero', function (): void {
    $actor = ReviewerTestModel::create();

    expect(app(WeightResolver::class)->resolve($actor, null, -3))->toBe(0);
});
