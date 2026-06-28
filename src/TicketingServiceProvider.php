<?php

declare(strict_types=1);

namespace Selli\Ticketing;

use Illuminate\Contracts\Auth\Factory;
use Selli\Ticketing\Contracts\TenantResolver;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\DefaultTenantResolver;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Workflow\ConfigValidator;
use Selli\Ticketing\Workflow\WorkflowManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class TicketingServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('ticketing')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(TenantResolver::class, function (): TenantResolver {
            /** @var class-string<TenantResolver> $class */
            $class = config('ticketing.tenancy.resolver', DefaultTenantResolver::class);

            if ($class === DefaultTenantResolver::class) {
                return new DefaultTenantResolver(
                    $this->app->make(Factory::class),
                    (string) config('ticketing.tenancy.column', 'tenant_id'),
                );
            }

            return $this->app->make($class);
        });

        $this->app->singleton(TenantContext::class, fn (): TenantContext => new TenantContext(
            resolver: $this->app->make(TenantResolver::class),
            enabled: config('ticketing.tenancy.enabled', true) !== false,
            column: (string) config('ticketing.tenancy.column', 'tenant_id'),
            allowShared: config('ticketing.tenancy.allow_shared', true) !== false,
        ));

        $this->app->singleton(Ticketing::class, fn (): Ticketing => new Ticketing($this->app));

        $this->app->singleton(WorkflowManager::class, fn (): WorkflowManager => new WorkflowManager($this->app));

        $this->app->bind(WorkflowDriver::class, fn (): WorkflowDriver => $this->app->make(WorkflowManager::class)->driver());
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ticketing-migrations');
        }

        if (config('ticketing.workflow.validate_on_boot', true) !== false) {
            $this->app->make(ConfigValidator::class)->validate();
        }
    }
}
