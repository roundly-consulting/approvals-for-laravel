<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Approvals\ApprovalsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /**
     * Every provider approvals needs, in registration order. `enums-for-laravel` is a
     * hard `require` but ships no provider (it is a helpers-only package), so the list
     * is genuinely one entry — not an omission.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [ApprovalsServiceProvider::class];
    }

    /**
     * The package's seven migrations, named by provider class (never by filename), plus
     * the host-owned fixture tables the approvables and actors live in.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            ApprovalsServiceProvider::class,
            __DIR__.'/database/migrations',
        ];
    }
}
