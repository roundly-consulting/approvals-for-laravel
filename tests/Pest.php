<?php

declare(strict_types=1);

use RoundlyConsulting\Approvals\Tests\Fixtures\SwappedApprovalsTestCase;
use RoundlyConsulting\Approvals\Tests\TestCase;

// Explicit paths, not `->in(__DIR__)`: the ModelSwap directory below needs a different
// base case, and a blanket bind would claim it first. ArchTest.php is listed because
// `swappableModelsAreNotFinal` reads the `approvals.*_model` config defaults and so needs
// the app booted — an arch file is not automatically test-cased.
uses(TestCase::class)->in('ArchTest.php', 'Feature', 'Unit');

// The model-swap proofs need the four `approvals.*_model` keys pointed at the host
// subclasses BEFORE the providers boot, so they run on their own base case in their own
// directory — Pest binds a test case per directory, not per file.
uses(SwappedApprovalsTestCase::class)->in('ModelSwap');
