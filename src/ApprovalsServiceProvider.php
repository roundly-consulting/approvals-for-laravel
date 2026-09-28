<?php

declare(strict_types=1);

namespace RoundlyConsulting\Approvals;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\Approvals\Commands\ExpireApprovalsCommand;
use RoundlyConsulting\Approvals\Facades\Approvals;
use RoundlyConsulting\Approvals\Support\ApprovalChecker;
use RoundlyConsulting\Approvals\Support\ApprovalDelegationModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestModelResolver;
use RoundlyConsulting\Approvals\Support\ApprovalRequestStageModelResolver;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBladeDirectives;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBlueprintMacros;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class ApprovalsServiceProvider extends PackageServiceProvider
{
    use RegistersBladeDirectives;
    use RegistersBlueprintMacros;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('approvals')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasCommands([
                ExpireApprovalsCommand::class,
            ])
            ->hasFacadeAlias(Approvals::class)
            ->contributesToAbout(static fn (): array => [
                'Model' => class_basename(ApprovalModelResolver::class()),
                'Request model' => class_basename(ApprovalRequestModelResolver::class()),
                'Stage model' => class_basename(ApprovalRequestStageModelResolver::class()),
                'Delegation model' => class_basename(ApprovalDelegationModelResolver::class()),
                'Default status' => self::defaultStatus(),
                'Authorization' => Config::boolean('approvals.authorization.enabled') ? 'ENFORCED' : 'OFF',
                // The gate ability is part of the host's own authorization
                // vocabulary, so the section reports whether one was configured —
                // never the ability's name.
                'Ability' => self::abilityPresence(),
                'Default expiry' => self::defaultExpiry(),
                // A workflow name is a host business process ("payout", "layoff"),
                // so the presets are reported by count, never by name.
                'Workflow presets' => self::workflowCount(),
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(ApprovalsManager::class);
    }

    public function boot(): void
    {
        parent::boot();

        // The migrations' key-type-aware morph columns are macros, so they must
        // exist before a host runs `php artisan migrate`.
        $this->registerBlueprintMacros();

        $this->registerBladeIf('approved', fn (Model $approvable, Model $actor): bool => ApprovalChecker::isApprovedBy($approvable, $actor));

        $this->registerBladeIf('rejected', fn (Model $approvable, Model $actor): bool => ApprovalChecker::isRejectedBy($approvable, $actor));

        $this->registerBladeIf('pendingApproval', fn (Model $approvable): bool => ApprovalChecker::hasPending($approvable));
    }

    private static function defaultStatus(): string
    {
        $status = config('approvals.default_status');

        return is_string($status) && $status !== '' ? $status : 'approved';
    }

    private static function abilityPresence(): string
    {
        $ability = config('approvals.authorization.ability');

        if (! is_string($ability) || $ability === '') {
            return 'NONE';
        }

        return $ability === 'decide-approval' ? 'DEFAULT' : 'SET';
    }

    private static function defaultExpiry(): string
    {
        $expiry = config('approvals.expiry.default');

        return is_int($expiry) ? $expiry.'s' : 'NEVER';
    }

    private static function workflowCount(): string
    {
        $workflows = config('approvals.workflows');

        if (! is_array($workflows) || $workflows === []) {
            return 'NONE';
        }

        return count($workflows).' defined';
    }
}
