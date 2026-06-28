<?php

declare(strict_types=1);

namespace Selli\Ticketing;

use Illuminate\Contracts\Auth\Factory;
use Illuminate\Support\Facades\Event;
use Selli\Ticketing\Automation\RuleEngine;
use Selli\Ticketing\Collaboration\NullMentionResolver;
use Selli\Ticketing\Commands\EscalateCommand;
use Selli\Ticketing\Commands\RecalculateSlaCommand;
use Selli\Ticketing\Contracts\MentionResolver;
use Selli\Ticketing\Contracts\TenantResolver;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Listeners\AutomationSubscriber;
use Selli\Ticketing\Listeners\CollaborationSubscriber;
use Selli\Ticketing\Listeners\CsatSubscriber;
use Selli\Ticketing\Listeners\RoutingSubscriber;
use Selli\Ticketing\Listeners\SlaSubscriber;
use Selli\Ticketing\Routing\AssignmentManager;
use Selli\Ticketing\Sla\SlaManager;
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
            ->hasConfigFile()
            ->hasCommands([
                EscalateCommand::class,
                RecalculateSlaCommand::class,
            ]);
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

        $this->app->singleton(SlaManager::class);

        $this->app->singleton(AssignmentManager::class, fn (): AssignmentManager => new AssignmentManager($this->app));

        // Scoped so the automation engine's re-entrancy depth counter is shared
        // within one request but reset between requests on a persistent worker.
        $this->app->scoped(RuleEngine::class);

        $this->app->bind(MentionResolver::class, function (): MentionResolver {
            /** @var class-string<MentionResolver> $class */
            $class = config('ticketing.collaboration.mentions.resolver', NullMentionResolver::class);

            return $this->app->make($class);
        });
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

        if (config('ticketing.sla.enabled', true) !== false) {
            $this->subscribe($this->app->make(SlaSubscriber::class));
        }

        if (config('ticketing.routing.enabled', true) !== false) {
            $this->subscribe($this->app->make(RoutingSubscriber::class));
        }

        if (config('ticketing.collaboration.mentions.enabled', true) !== false) {
            $this->subscribe($this->app->make(CollaborationSubscriber::class));
        }

        if (config('ticketing.csat.enabled', true) !== false) {
            $this->subscribe($this->app->make(CsatSubscriber::class));
        }

        if (config('ticketing.automation.enabled', true) !== false) {
            $this->subscribe($this->app->make(AutomationSubscriber::class));
        }
    }

    /**
     * Register an event subscriber's listeners on the dispatcher.
     *
     * The handlers are wrapped so a failure in a side-effect (SLA, routing) is
     * reported but never propagates back to the action that emitted the event —
     * a misconfigured rule must not fail (or half-complete) a ticket open.
     */
    protected function subscribe(object $subscriber): void
    {
        /** @var array<class-string, string> $map */
        $map = $subscriber->subscribe();

        foreach ($map as $event => $method) {
            Event::listen($event, function (object $payload) use ($subscriber, $method): void {
                try {
                    $subscriber->{$method}($payload);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
        }
    }
}
