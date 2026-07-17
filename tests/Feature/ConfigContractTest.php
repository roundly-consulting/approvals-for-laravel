<?php

declare(strict_types=1);

/**
 * The config contract approvals never had, pinned in both directions:
 *
 *  - forward — every key the code reads is shipped. This is shops #18, whose whole
 *    store-credit feature read `shops.payments.*` while the file shipped `payment.*`;
 *    330 tests stayed green because the suite set the same wrong key.
 *  - reverse — every shipped leaf is read. A documented key nothing reads is dead
 *    config that lies to the host: media #27's `max_file_size` cap that never applied,
 *    alerts #24's thrice-documented `escalation` key. Approvals is a package where that
 *    lie is expensive — a host reading `approvals.authorization.enabled` in the config
 *    file believes decisions are gated.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/approvals.php')->toSatisfyConfigContract([__DIR__.'/../../src', __DIR__.'/../../database'], [
        // The four `approvals.*_model` keys are read through the toolkit's
        // `ModelResolver::for('approvals.…')` seam (via ConfiguredApprovalsModel), and
        // `approvals.key_type` through `KeyType::fromConfig('approvals.key_type')` in the
        // migrations (hence `database` in the scanned dirs). They are real reads — the model
        // keys drive every swap, the key type decides the shipped morph column types — but
        // none is a `config(` token, so the prefix is what makes them visible to the scraper.
        'extraReadPrefixes' => ['approvals.'],

        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a
        // read" — but this provider's `contributesToAbout()` closure calls
        // `config('approvals.…')` for real (default_status, authorization.enabled,
        // authorization.ability, expiry.default, workflows), and for several of those it
        // is the only reader in the package. Excluding it would discard readers and
        // weaken the reverse direction for nothing.
    ]);
});
