<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalRule;
use RoundlyConsulting\Approvals\Tests\ActorTestModel;
use RoundlyConsulting\Approvals\Tests\DeploymentTestModel;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApproval;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApprovalDelegation;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApprovalRequest;
use RoundlyConsulting\Approvals\Tests\Fixtures\CustomApprovalRequestStage;
use RoundlyConsulting\Approvals\Tests\Fixtures\SwappedApprovalsTestCase;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;

/**
 * The model-swap proofs (S) for approvals' four seams, each driven through the REAL
 * flow rather than a resolver string check.
 *
 * `tests/Unit/ApprovalModelResolverTest.php` already pins that the resolver *validates*
 * what it is handed (it rejects a non-model, a null, a foreign model) — that is domain
 * behaviour and stays where it is. What it cannot prove is the thing this file exists
 * for: that the package actually *uses* the configured class when a host drives a real
 * approval. It sets config at runtime and asserts a class-string, so it would stay green
 * with every observer still hung on the packaged model (media #28).
 *
 * The swap is applied before boot by {@see SwappedApprovalsTestCase}, which this
 * directory is bound to — Pest binds a test case per directory, not per file.
 */
it('honours a host approval model through the decision flow', function (): void {
    expect('approvals.model')->toHonourModelSwap(CustomApproval::class, function (): array {
        $actor = ActorTestModel::query()->create();
        $deployment = DeploymentTestModel::query()->create();

        // A decision, a rejection and a toggle — the flows a host actually calls.
        $approved = $actor->approve($deployment, 'ship it');
        $rejected = $actor->reject(DeploymentTestModel::query()->create(), 'not yet');

        return [
            $approved,
            $rejected,
            // The morph relation hydrates through the seam too, not just the writes.
            ...$deployment->approvals()->get()->all(),
            ...$actor->approvals()->get()->all(),
        ];
    });
});

it('honours a host request model through the request flow', function (): void {
    expect('approvals.request_model')->toHonourModelSwap(CustomApprovalRequest::class, function (): array {
        $release = ReleaseTestModel::query()->create();
        $first = ActorTestModel::query()->create();
        $second = ActorTestModel::query()->create();

        $request = $release->requestApproval([$first, $second], ApprovalRule::Unanimous);

        return [
            $request,
            ...$release->approvalRequests()->get()->all(),
        ];
    });
});

/**
 * The stage seam is the one a host never creates itself — the package opens stage rows
 * on its behalf from inside RequestStagedApprovalAction. That is exactly the shape
 * permissions #31 broke on (`static::query()->create()` resolving the packaged class),
 * so the created-event count on the host subclass is the proof that matters here.
 */
it('honours a host stage model when the package opens stages on the host behalf', function (): void {
    expect('approvals.stage_model')->toHonourModelSwap(CustomApprovalRequestStage::class, function (): array {
        $release = ReleaseTestModel::query()->create();
        $engineer = ActorTestModel::query()->create();
        $product = ActorTestModel::query()->create();

        $request = $release->requestStagedApproval([
            new StageDefinition(approvers: [$engineer], rule: ApprovalRule::Unanimous, name: 'engineering'),
            new StageDefinition(approvers: [$product], rule: ApprovalRule::Any, name: 'product'),
        ]);

        return [
            ...$request->stages()->get()->all(),
        ];
    });
});

it('honours a host delegation model through the delegation flow', function (): void {
    expect('approvals.delegation_model')->toHonourModelSwap(CustomApprovalDelegation::class, function (): array {
        $delegator = ActorTestModel::query()->create();
        $delegate = ActorTestModel::query()->create();

        $delegation = $delegator->delegateApprovalsTo($delegate)->save();

        return [
            $delegation,
            ...$delegator->approvalDelegations()->get()->all(),
        ];
    });
});

/**
 * The seams must survive the path a host is most likely to depend on: the checker that
 * answers "is this approved?". If the checker queried the packaged model while the
 * writes went through the host's, the answer would be derived from a different table
 * read than the decisions that were written.
 */
it('answers approval checks through the swapped models', function (): void {
    $actor = ActorTestModel::query()->create();
    $deployment = DeploymentTestModel::query()->create();

    $actor->approve($deployment);

    expect($deployment->hasBeenApprovedBy($actor))->toBeTrue()
        ->and($deployment->approvals()->first())->toBeInstanceOf(CustomApproval::class)
        ->and($actor->approvalFor($deployment))->toBeInstanceOf(CustomApproval::class)
        ->and($deployment->approvalCount())->toBe(1);
});

// The structural half of each seam — the four models are non-final, and each
// `approvals.*_model` key really defaults to its packaged model — is pinned once in
// tests/ArchTest.php by `ArchPresets::swappableModelsAreNotFinal()`. It deliberately does
// NOT live here: that preset asserts the config *default*, which this directory has
// swapped away.
