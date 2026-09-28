<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Actions\OpenApprovalRequestAction;
use RoundlyConsulting\Approvals\DataTransferObjects\ApprovalRequestData;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Exceptions\InvalidApprovalRequestException;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;
use RoundlyConsulting\Approvals\Tests\WeightedReviewerTestModel;

/**
 * Threshold rules used to compare a weight threshold against a headcount: a weighted
 * request whose light approver decided first was rejected by that approval.
 */
function weighted(int $weight): WeightedReviewerTestModel
{
    $reviewer = WeightedReviewerTestModel::create();
    $reviewer->weight = $weight;

    return $reviewer;
}

it('keeps a weighted request open when the light approver decides first', function (): void {
    $release = ReleaseTestModel::create();
    $director = weighted(3);
    $analyst = weighted(1);

    $release->requestApproval([$director, $analyst], ApprovalRule::Weighted, quorum: 3);

    $analyst->approve($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    $director->approve($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('rejects a weighted request once the outstanding weight cannot reach the threshold', function (): void {
    $release = ReleaseTestModel::create();
    $director = weighted(3);
    $analyst = weighted(1);
    $intern = weighted(1);

    $release->requestApproval([$director, $analyst, $intern], ApprovalRule::Weighted, quorum: 4);

    $analyst->approve($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    $director->reject($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});

it('counts heads, not weights, for a unanimous request', function (): void {
    $release = ReleaseTestModel::create();
    $director = weighted(3);
    $analyst = weighted(1);

    $release->requestApproval([$director, $analyst], ApprovalRule::Unanimous);

    $director->approve($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    $analyst->approve($release);
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('refuses a threshold its named approvers can never reach', function (ApprovalRule $rule, int $quorum): void {
    $reviewers = [weighted(1), weighted(1)];

    expect(fn () => ReleaseTestModel::create()->requestApproval($reviewers, $rule, $quorum))
        ->toThrow(InvalidApprovalRequestException::class, "threshold of {$quorum}")
        ->and(ApprovalRequest::query()->count())->toBe(0);
})->with([
    'quorum above the headcount' => [ApprovalRule::Quorum, 3],
    'weight above the total' => [ApprovalRule::Weighted, 5],
]);

it('refuses a threshold below one', function (): void {
    expect(fn () => Approvals::request(ReleaseTestModel::create())->from([ReviewerTestModel::create()])->quorum(0)->open())
        ->toThrow(InvalidApprovalRequestException::class, 'at least 1');
});

it('refuses an unreachable stage threshold', function (): void {
    expect(fn () => ReleaseTestModel::create()->requestStagedApproval([
        new StageDefinition([weighted(1)], ApprovalRule::Quorum, quorum: 2),
    ]))->toThrow(InvalidApprovalRequestException::class);
});

it('refuses an unnamed quorum above its headcount', function (): void {
    expect(fn () => app(OpenApprovalRequestAction::class)->execute(
        new ApprovalRequestData(
            ReleaseTestModel::create(),
            [],
            ApprovalRule::Quorum,
            quorum: 3,
            requiredApprovers: 2,
        ),
    ))->toThrow(InvalidApprovalRequestException::class);
});

it('only rejects an unnamed weighted request once every slot has decided', function (): void {
    $release = ReleaseTestModel::create();

    ApprovalRequest::factory()->forSubject($release)->weighted(3)->create(['required_approvers' => 2]);

    Approvals::for($release)->as(ReviewerTestModel::create())->weight(1)->approve();
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Pending);

    Approvals::for($release)->as(ReviewerTestModel::create())->weight(1)->approve();
    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Rejected);
});

it('lets an unnamed weighted request reach its threshold through a heavy approver', function (): void {
    $release = ReleaseTestModel::create();

    ApprovalRequest::factory()->forSubject($release)->weighted(3)->create(['required_approvers' => 2]);

    Approvals::for($release)->as(ReviewerTestModel::create())->weight(1)->approve();
    Approvals::for($release)->as(ReviewerTestModel::create())->weight(2)->approve();

    expect($release->currentApprovalStatus())->toBe(ApprovalStatus::Approved);
});

it('reports weighted progress in weight and unanimous progress in heads', function (): void {
    $release = ReleaseTestModel::create();
    $director = weighted(3);
    $analyst = weighted(1);

    $release->requestApproval([$director, $analyst], ApprovalRule::Unanimous);
    $director->approve($release);

    expect($release->approvalProgress()?->approved)->toBe(1)
        ->and($release->approvalProgress()?->percentage())->toBe(50);
});
