<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals;

use Illuminate\Support\ServiceProvider;

final class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/approvals.php', 'approvals');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/approvals.php' => config_path('approvals.php'),
            ], 'approvals-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'approvals-migrations');
        }
    }
}
