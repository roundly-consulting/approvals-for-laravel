<?php

declare(strict_types=1);

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`,
 * which returns `''`. Every "does not leak" check was vacuous — passing against empty
 * output. This capture goes through `Artisan::call('about')` and asserts the output is
 * non-empty and renders every `mustRender` string *before* it looks for a secret.
 *
 * Approvals' section carries no credentials, which is exactly why it is worth pinning:
 * the risk here is not an API key but the host's own **authorization vocabulary and
 * business processes**. The provider is deliberately written to report the gate ability
 * by presence (DEFAULT/SET/NONE) and workflows by count — because an ability name
 * ("approve-payout") and a workflow name ("layoff", "acquisition") are host secrets that
 * leak intent. This test is what stops a future "helpful" change from rendering them.
 */
it('renders the approvals section without leaking the host authorization vocabulary', function (): void {
    config()->set('approvals.authorization.enabled', true);
    config()->set('approvals.authorization.ability', 'approve-payout-over-10k');
    config()->set('approvals.expiry.default', 86400);
    config()->set('approvals.workflows', [
        'project-titan-layoff' => ['rule' => 'unanimous', 'required_approvers' => 2],
        'acquisition-signoff' => ['rule' => 'any', 'required_approvers' => 1],
    ]);

    expect('approvals')->toLeakNoSecrets(
        secrets: [
            // The gate ability is part of the host's authorization vocabulary — the
            // section reports that one was configured, never its name.
            'approve-payout-over-10k',
            // A workflow name is a host business process. Reported by count only.
            'project-titan-layoff',
            'acquisition-signoff',
        ],
        mustRender: [
            'Model',
            'Request model',
            'Stage model',
            'Delegation model',
            'Default status',
            'Authorization',
            'Ability',
            'Default expiry',
            'Workflow presets',
            // The positive halves that prove the lines are reporting rather than
            // silently empty: the count itself, and the presence marker.
            '2 defined',
            'SET',
            'ENFORCED',
        ],
    );
});
