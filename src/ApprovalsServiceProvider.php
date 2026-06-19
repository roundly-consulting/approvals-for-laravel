<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Approvals\Commands\ExpireApprovalsCommand;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalManager;

final class ApprovalsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/approvals.php', 'approvals');

        $this->app->singleton(ApprovalManager::class);

        $this->app->booting(function (): void {
            AliasLoader::getInstance()->alias('Approvals', Approvals::class);
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->registerBladeDirectives();

        if ($this->app->runningInConsole()) {
            $this->commands([
                ExpireApprovalsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/approvals.php' => config_path('approvals.php'),
            ], 'approvals-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'approvals-migrations');
        }
    }

    private function registerBladeDirectives(): void
    {
        Blade::if('approved', fn (Model $approvable, Model $actor): bool => ApprovalChecker::isApprovedBy($approvable, $actor));

        Blade::if('rejected', fn (Model $approvable, Model $actor): bool => ApprovalChecker::isRejectedBy($approvable, $actor));

        Blade::if('pendingApproval', fn (Model $approvable): bool => ApprovalChecker::hasPending($approvable));
    }
}
