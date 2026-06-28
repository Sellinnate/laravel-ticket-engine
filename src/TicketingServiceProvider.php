<?php

declare(strict_types=1);

namespace Selli\Ticketing;

use Illuminate\Contracts\Auth\Factory;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Selli\Ticketing\Automation\RuleEngine;
use Selli\Ticketing\Collaboration\NullMentionResolver;
use Selli\Ticketing\Commands\EscalateCommand;
use Selli\Ticketing\Commands\RecalculateSlaCommand;
use Selli\Ticketing\Contracts\MentionResolver;
use Selli\Ticketing\Contracts\NotificationPreferences;
use Selli\Ticketing\Contracts\TenantResolver;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Listeners\AutomationSubscriber;
use Selli\Ticketing\Listeners\CollaborationSubscriber;
use Selli\Ticketing\Listeners\CsatSubscriber;
use Selli\Ticketing\Listeners\NotificationSubscriber;
use Selli\Ticketing\Listeners\RoutingSubscriber;
use Selli\Ticketing\Listeners\SlaSubscriber;
use Selli\Ticketing\Notifications\ConfigNotificationPreferences;
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

        $this->app->bind(NotificationPreferences::class, function (): NotificationPreferences {
            /** @var class-string<NotificationPreferences> $class */
            $class = config('ticketing.notifications.preferences', ConfigNotificationPreferences::class);

            return $this->app->make($class);
        });
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ticketing-migrations');

            $this->publishes([
                __DIR__.'/../routes/api.php' => base_path('routes/ticketing-api.php'),
            ], 'ticketing-routes');
        }

        $this->registerApiRoutes();

        if (config('ticketing.workflow.validate_on_boot', true) !== false) {
            $this->app->make(ConfigValidator::class)->validate();
        }

        if (config('ticketing.sla.enabled', true) !== false) {
            $this->subscribe($this->app->make(SlaSubscriber::class));
        }

        if (config('ticketing.routing.enabled', true) !== false) {
            $this->subscribe($this->app->make(RoutingSubscriber::class));
        }

        // Notifications subscribe BEFORE collaboration so a reply's recipients
        // are resolved from the existing participants; a freshly @mentioned user
        // (added by the collaboration listener for the same message) then only
        // receives the "added to ticket" notification, not also the reply one.
        if (config('ticketing.notifications.enabled', true) !== false) {
            $this->subscribe($this->app->make(NotificationSubscriber::class));
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
     * Mount the opt-in REST API under the configured prefix/version + middleware,
     * binding {ticket} to the configured (tenant-scoped) model.
     */
    protected function registerApiRoutes(): void
    {
        if (config('ticketing.api.enabled', false) !== true) {
            return;
        }

        $prefix = trim((string) config('ticketing.api.prefix', 'ticketing/api'), '/')
            .'/'.trim((string) config('ticketing.api.version', 'v1'), '/');

        // Always include SubstituteBindings (implicit {ticket} resolution, which
        // applies the model's tenant scope so a cross-tenant id 404s) and the
        // throttle, even if the host overrides the rest of the middleware. The
        // binding stays scoped to this group rather than registered globally.
        $middleware = array_values(array_unique(array_merge(
            (array) config('ticketing.api.middleware', ['api']),
            [SubstituteBindings::class, 'throttle:'.config('ticketing.api.throttle', '120,1')],
        )));

        Route::group(['prefix' => $prefix, 'middleware' => $middleware], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });
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
