<?php

declare(strict_types=1);

namespace Selli\Ticketing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Selli\Ticketing\Automation\RuleEngine;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Broadcasting\DefaultChannelAuthorizer;
use Selli\Ticketing\Collaboration\NullMentionResolver;
use Selli\Ticketing\Commands\EscalateCommand;
use Selli\Ticketing\Commands\RecalculateSlaCommand;
use Selli\Ticketing\Contracts\ChannelAuthorizer;
use Selli\Ticketing\Contracts\MentionResolver;
use Selli\Ticketing\Contracts\NotificationPreferences;
use Selli\Ticketing\Contracts\TenantResolver;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Listeners\AutomationSubscriber;
use Selli\Ticketing\Listeners\BroadcastSubscriber;
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

        // Default, tenant-scoped broadcast channel authorization. bindIf so a
        // host that already bound its own ChannelAuthorizer (e.g. delegating to
        // its policies) keeps it — we never override the host's broadcast policy.
        $this->app->bindIf(ChannelAuthorizer::class, DefaultChannelAuthorizer::class);
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

        if (config('ticketing.broadcasting.enabled', false) === true) {
            $this->subscribe($this->app->make(BroadcastSubscriber::class));
            $this->registerBroadcastChannels();
        }
    }

    /**
     * Register the three private channels' authorization callbacks, each
     * delegating to the bound ChannelAuthorizer. Skipped when the host opts to
     * wire the channels itself (broadcasting.register_channels = false).
     */
    protected function registerBroadcastChannels(): void
    {
        if (config('ticketing.broadcasting.register_channels', true) !== true) {
            return;
        }

        $patterns = Channels::patterns();

        Broadcast::channel(
            $patterns['tenantTickets'],
            fn (Authenticatable $user, int|string $tenantId): bool => $this->app->make(ChannelAuthorizer::class)->forTenantTickets($user, $tenantId),
        );

        Broadcast::channel(
            $patterns['agent'],
            fn (Authenticatable $user, int|string $tenantId, string $agentType, int|string $agentId): bool => $this->app->make(ChannelAuthorizer::class)->forAgent($user, $tenantId, $agentType, $agentId),
        );

        Broadcast::channel(
            $patterns['ticketAgents'],
            fn (Authenticatable $user, int|string $ticketId): bool => $this->app->make(ChannelAuthorizer::class)->forTicketAgents($user, $ticketId),
        );

        Broadcast::channel(
            $patterns['ticket'],
            fn (Authenticatable $user, int|string $ticketId): bool => $this->app->make(ChannelAuthorizer::class)->forTicket($user, $ticketId),
        );
    }

    /**
     * Mount the opt-in REST API under the configured prefix/version + middleware.
     * Controllers resolve {ticket} through the configured, tenant-scoped model.
     */
    protected function registerApiRoutes(): void
    {
        if (config('ticketing.api.enabled', false) !== true) {
            return;
        }

        $prefix = trim((string) config('ticketing.api.prefix', 'ticketing/api'), '/')
            .'/'.trim((string) config('ticketing.api.version', 'v1'), '/');

        // Include the throttle even if the host overrides the rest of the
        // middleware. {ticket} is NOT route-model-bound: each controller resolves
        // it through Ticketing::ticketModel() (honouring a host's
        // useTicketModel()/config override and the tenant scope), so we register
        // no global Route::bind that would leak onto the host's own {ticket} routes.
        $middleware = array_values(array_unique(array_merge(
            (array) config('ticketing.api.middleware', ['api']),
            ['throttle:'.config('ticketing.api.throttle', '120,1')],
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
