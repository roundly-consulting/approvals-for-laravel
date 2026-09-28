<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\ApprovalsManager;
use RoundlyConsulting\Approvals\Exceptions\ApprovalsException;
use RoundlyConsulting\Approvals\Models\Approval;
use RoundlyConsulting\Approvals\Models\ApprovalDelegation;
use RoundlyConsulting\Approvals\Models\ApprovalRequest;
use RoundlyConsulting\Approvals\Models\ApprovalRequestStage;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * Approvals shipped with no architecture test at all, so every preset here is a new
 * guard rather than a replacement — including the two that matter most for a package
 * that documents four model seams and whose `require` a host installs at runtime.
 */
ArchPresets::strictTypes('RoundlyConsulting\Approvals');

/**
 * The deliberate extension points are exempt: the four models `approvals.*_model` invite
 * a host to subclass (pinned by the preset below instead), ApprovalsException, the
 * base every approvals error extends so a host can catch them uniformly, and
 * ApprovalsManager, which ApprovalsFake extends so an injected manager gets the fake.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Approvals', [
    Approval::class,
    ApprovalRequest::class,
    ApprovalRequestStage::class,
    ApprovalDelegation::class,
    ApprovalsException::class,
    ApprovalsManager::class,
]);

/**
 * The counter-weight, and the fleet's 7×-shipped fatal: `final` on a config-swappable
 * model is a PHP fatal the moment a host uses the seam the config documents. Four seams
 * means four ways to ship it. The preset also pins that each key really defaults to its
 * packaged model, so a seam cannot rot in the other direction either.
 */
ArchPresets::swappableModelsAreNotFinal([
    Approval::class => 'approvals.model',
    ApprovalRequest::class => 'approvals.request_model',
    ApprovalRequestStage::class => 'approvals.stage_model',
    ApprovalDelegation::class => 'approvals.delegation_model',
]);

/**
 * Approvals does no cryptography; the ban is a standing guard against a token or
 * signature scheme being hand-rolled here rather than in crypto-for-laravel.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Approvals');

/**
 * Every `approvals.*_model` read goes through a resolver in Support (each delegating to
 * ConfiguredApprovalsModel). Adopted rather than rejected as jwt rejected it: approvals
 * has exactly the shape the preset targets — real Eloquent models behind `*_model` keys,
 * resolved through a Support seam — so the stray-literal half has something to say, and
 * nothing here needs the late static binding the preset bans.
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../src', 'Support');

/**
 * The Dependency Policy as a test. No `alsoAllow`: approvals' `require` ships only
 * php/illuminate/roundly, and the workflow installs test tooling with `--dev`, so nothing
 * legitimately lands in `require` that this must forgive. If this goes red, the graph is
 * wrong — never widen the allow-list to quiet it.
 */
/**
 * The morph-key seam, guarded. The actor, approvable, subject, decided_by and delegation
 * columns migrated off raw `$table->morphs()` onto `morphKey($name, KeyType::fromConfig(...))`
 * so a uuid/ulid host can flip its whole graph coherently — a hardcoded bigint id breaks
 * those hosts on Postgres, and SQLite type affinity hides it. This pin reds if a future
 * migration reintroduces a raw morph.
 */
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../database/migrations');

ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

ArchPresets::noDebuggingLeftovers();

/**
 * One path into the behaviour: GivesApprovals and RequiresApproval reach every action
 * through ApprovalsManager, so Approvals::fake() records calls made through the model
 * traits as well as through the facade.
 */
ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Approvals');
