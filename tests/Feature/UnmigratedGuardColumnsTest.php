<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Approvals\DataTransferObjects\StageDefinition;
use RoundlyConsulting\Approvals\Enums\ApprovalStatus;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Tests\ReleaseTestModel;
use RoundlyConsulting\Approvals\Tests\ReviewerTestModel;

/**
 * A host that updates to 2.2.0 before it publishes and runs migration 0008 has none of the
 * guard columns. Until it uses a guard, nothing may read or write them — even on a strict
 * model that throws on a missing attribute.
 */
afterEach(fn () => Model::preventAccessingMissingAttributes(false));

it('keeps deciding, replacing and re-checking without the 2.2.0 columns', function (): void {
    Schema::table('approval_requests', function (Blueprint $table): void {
        $table->dropColumn(['excluded_approvers', 'subject_fingerprint']);
    });
    Schema::table('approvals', function (Blueprint $table): void {
        $table->dropColumn('subject_fingerprint');
    });

    Model::preventAccessingMissingAttributes();

    [$a, $b, $m] = [ReviewerTestModel::create(), ReviewerTestModel::create(), ReviewerTestModel::create()];

    $flat = ReleaseTestModel::create();
    Approvals::request($flat)->from([$a, $b])->open();
    Approvals::for($flat)->as($b)->ask();
    $a->approve($flat);
    Approvals::for($flat)->replaceFlatApprovers([$m]);

    expect(Approvals::for($flat)->recheckThreshold()?->reachable)->toBeTrue()
        ->and(Approvals::subjectChanged($flat))->toBeFalse()
        ->and(Approvals::for($flat)->voidIfChanged())->toBe(0);

    $m->approve($flat);

    $staged = ReleaseTestModel::create();
    Approvals::request($staged)->stages([new StageDefinition([$a], name: 'one')])->open();
    $a->reject($staged);

    expect(Approvals::status($flat))->toBe(ApprovalStatus::Approved)
        ->and(Approvals::status($staged))->toBe(ApprovalStatus::Rejected);
});
