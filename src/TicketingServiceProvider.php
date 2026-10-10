<?php

declare(strict_types=1);

namespace Selli\Ticketing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Selli\Ticketing\Ai\AiManager;
use Selli\Ticketing\Ai\ConfigAiSettings;
use Selli\Ticketing\Ai\TeamLeadEscalationRecipients;
use Selli\Ticketing\Automation\RuleEngine;
use Selli\Ticketing\Broadcasting\Channels;
use Selli\Ticketing\Broadcasting\DefaultChannelAuthorizer;
use Selli\Ticketing\Collaboration\NullMentionResolver;
use Selli\Ticketing\Commands\DemoCommand;
use Selli\Ticketing\Commands\EscalateCommand;
use Selli\Ticketing\Commands\PollMailCommand;
use Selli\Ticketing\Commands\PruneCommand;
use Selli\Ticketing\Commands\RagIndexCommand;
use Selli\Ticketing\Commands\RecalculateSlaCommand;
use Selli\Ticketing\Contacts\ContactDirectory;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Contracts\ChannelAuthorizer;
use Selli\Ticketing\Contracts\EscalationRecipients;
use Selli\Ticketing\Contracts\HumanVerifier;
use Selli\Ticketing\Contracts\InboundMailRouter;
use Selli\Ticketing\Contracts\InboundRequesterResolver;
use Selli\Ticketing\Contracts\KnowledgeSearch;
use Selli\Ticketing\Contracts\MentionResolver;
use Selli\Ticketing\Contracts\NotificationPreferences;
use Selli\Ticketing\Contracts\TenantResolver;
use Selli\Ticketing\Contracts\VisitorResolver;
use Selli\Ticketing\Contracts\WorkflowDriver;
use Selli\Ticketing\Conversations\ConversationService;
use Selli\Ticketing\Conversations\NullVisitorResolver;
use Selli\Ticketing\Conversations\WidgetKeys;
use Selli\Ticketing\Http\Middleware\ResolveWidgetKey;
use Selli\Ticketing\Http\Middleware\WidgetCors;
use Selli\Ticketing\Integrations\RagEngine\RagKnowledge;
use Selli\Ticketing\Integrations\RagEngine\RagKnowledgeSearch;
use Selli\Ticketing\Integrations\RagEngine\RagSyncJob;
use Selli\Ticketing\Knowledge\KeywordKnowledgeSearch;
use Selli\Ticketing\Knowledge\KnowledgeBase;
use Selli\Ticketing\Listeners\AiSubscriber;
use Selli\Ticketing\Listeners\AutomationSubscriber;
use Selli\Ticketing\Listeners\BroadcastSubscriber;
use Selli\Ticketing\Listeners\CollaborationSubscriber;
use Selli\Ticketing\Listeners\CsatSubscriber;
use Selli\Ticketing\Listeners\NotificationSubscriber;
use Selli\Ticketing\Listeners\RagSubscriber;
use Selli\Ticketing\Listeners\RoutingSubscriber;
use Selli\Ticketing\Listeners\SlaSubscriber;
use Selli\Ticketing\Listeners\Subscriber;
use Selli\Ticketing\Listeners\WorkflowSubscriber;
use Selli\Ticketing\Mail\ConfigInboundMailRouter;
use Selli\Ticketing\Mail\ContactInboundRequesterResolver;
use Selli\Ticketing\Mail\MailboxManager;
use Selli\Ticketing\Mail\NullInboundRequesterResolver;
use Selli\Ticketing\Models\WidgetKey;
use Selli\Ticketing\Notifications\ConfigNotificationPreferences;
use Selli\Ticketing\Policies\KbArticlePolicy;
use Selli\Ticketing\Policies\TicketPolicy;
use Selli\Ticketing\Routing\AssignmentManager;
use Selli\Ticketing\Sla\SlaManager;
use Selli\Ticketing\Support\NullHumanVerifier;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\DefaultTenantResolver;
use Selli\Ticketing\Tenancy\TenantContext;
use Selli\Ticketing\Workflow\ConfigValidator;
use Selli\Ticketing\Workflow\WorkflowManager;
use Sellinnate\RagEngine\RagEngine;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
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
                PruneCommand::class,
                DemoCommand::class,
                PollMailCommand::class,
                RagIndexCommand::class,
            ])
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->publishMigrations()
                    ->askToRunMigrations()
                    ->askToStarRepoOnGitHub('Sellinnate/laravel-ticket-engine');
            });
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

        $this->app->singleton(AiManager::class, fn (): AiManager => new AiManager($this->app));
        $this->app->singleton(KnowledgeBase::class);
        $this->app->singleton(ContactDirectory::class);
        $this->app->singleton(ConversationService::class);
        $this->app->singleton(WidgetKeys::class);

        // Keyword search by default; the RAG Engine bridge (or the host) binds a
        // semantic implementation.
        if ($this->ragEnabled() && config('ticketing.rag.knowledge_search', false) === true) {
            $this->app->bindIf(KnowledgeSearch::class, RagKnowledgeSearch::class);
        }

        $this->app->bindIf(KnowledgeSearch::class, KeywordKnowledgeSearch::class);

        $this->app->bind(AiSettingsResolver::class, function (): AiSettingsResolver {
            /** @var class-string<AiSettingsResolver> $class */
            $class = config('ticketing.ai.settings', ConfigAiSettings::class);

            return $this->app->make($class);
        });

        $this->app->bind(HumanVerifier::class, function (): HumanVerifier {
            /** @var class-string<HumanVerifier> $class */
            $class = config('ticketing.widget.human_verifier', NullHumanVerifier::class);

            return $this->app->make($class);
        });

        $this->app->bindIf(EscalationRecipients::class, TeamLeadEscalationRecipients::class);
        $this->app->bindIf(VisitorResolver::class, NullVisitorResolver::class);

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

        // Email-to-ticket: config-driven recipient routing + a null requester
        // resolver by default. bindIf so a host's own bindings win.
        $this->app->bindIf(InboundMailRouter::class, ConfigInboundMailRouter::class);
        $this->app->singletonIf(MailboxManager::class);
        $this->app->bindIf(InboundRequesterResolver::class, fn (): InboundRequesterResolver => config('ticketing.contacts.inbound_email', true) !== false
            ? $this->app->make(ContactInboundRequesterResolver::class)
            : $this->app->make(NullInboundRequesterResolver::class));
    }

    public function packageBooted(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ticketing-migrations');

            $this->publishes([
                __DIR__.'/../routes/api.php' => base_path('routes/ticketing-api.php'),
                __DIR__.'/../routes/widget.php' => base_path('routes/ticketing-widget.php'),
            ], 'ticketing-routes');

            // The optional, dependency-free reference widget client.
            $this->publishes([
                __DIR__.'/../resources/js/ticketing-widget.js' => public_path('vendor/ticketing/ticketing-widget.js'),
            ], 'ticketing-widget');
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

        if (config('ticketing.workflow.reopen_on_reply.enabled', true) !== false) {
            $this->subscribe($this->app->make(WorkflowSubscriber::class));
        }

        if (config('ticketing.ai.enabled', false) === true) {
            $this->subscribe($this->app->make(AiSubscriber::class));
        }

        if ($this->ragEnabled()) {
            $this->subscribe($this->app->make(RagSubscriber::class));

            // Canned responses have no domain events: their model events sync them.
            if (config('ticketing.rag.index.canned_responses', true) !== false) {
                $canned = Ticketing::cannedResponseModel();
                $sync = fn (Model $response) => RagSyncJob::sync(RagKnowledge::TYPE_CANNED, $response->getKey(), $response->getAttribute($canned::make()->getTenantColumn()));
                $canned::saved($sync);
                $canned::deleted($sync);
            }
        }

        if (config('ticketing.broadcasting.enabled', false) === true) {
            $this->subscribe($this->app->make(BroadcastSubscriber::class));
            $this->registerBroadcastChannels();
        }

        if (config('ticketing.authorization.register_policies', true) !== false) {
            Gate::policy(Ticketing::ticketModel(), TicketPolicy::class);
            Gate::policy(Ticketing::kbArticleModel(), KbArticlePolicy::class);
        }

        $this->registerWidgetRoutes();
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
     * Mount the opt-in public widget API (conversations + public knowledge
     * base) with its own rate limiters. Every route resolves a widget key
     * first, which also fixes the tenant for the request.
     */
    protected function registerWidgetRoutes(): void
    {
        if (config('ticketing.widget.enabled', false) !== true) {
            return;
        }

        RateLimiter::for('ticketing-widget', function (Request $request): array {
            // Counted on the key ResolveWidgetKey resolved (the throttle runs
            // after it), so no spelling of the header gets a counter of its own.
            $key = $request->attributes->get(ResolveWidgetKey::ATTRIBUTE);
            $counter = $key instanceof WidgetKey
                ? 'id:'.$key->getKey()
                : 'raw:'.hash('sha256', (string) $request->header('X-Ticketing-Key', ''));

            return [
                self::limit((string) config('ticketing.widget.rate_limits.per_ip', '60,1'))->by('ip:'.$request->ip()),
                self::limit((string) config('ticketing.widget.rate_limits.per_key', '1200,1'))->by('key:'.$counter),
            ];
        });

        RateLimiter::for('ticketing-widget-start', fn (Request $request): Limit => self::limit(
            (string) config('ticketing.widget.rate_limits.conversations_per_ip', '10,60'),
        )->by('start:'.$request->ip()));

        $prefix = trim((string) config('ticketing.widget.prefix', 'ticketing/widget'), '/')
            .'/'.trim((string) config('ticketing.widget.version', 'v1'), '/');

        $middleware = array_values(array_unique(array_merge(
            (array) config('ticketing.widget.middleware', ['api']),
            // CORS first (it answers preflights), then the key — so even a 429
            // carries the allowed origin and the browser lets the client see it.
            [WidgetCors::class, ResolveWidgetKey::class, 'throttle:ticketing-widget'],
        )));

        // Laravel sorts route middleware by its priority list, which would move
        // the throttle ahead of these two (before SubstituteBindings in the
        // "api" group): rank them first so CORS and the key always come first.
        $kernel = $this->app->make(HttpKernel::class);

        if ($kernel instanceof FoundationHttpKernel) {
            $kernel->prependToMiddlewarePriority(ResolveWidgetKey::class);
            $kernel->prependToMiddlewarePriority(WidgetCors::class);
        }

        Route::group(['prefix' => $prefix, 'middleware' => $middleware], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/widget.php');
        });
    }

    /**
     * A limit from "max,minutes".
     */
    protected static function limit(string $definition): Limit
    {
        [$max, $minutes] = array_map('intval', array_pad(explode(',', $definition), 2, 1));

        return Limit::perMinutes(max(1, $minutes), max(1, $max));
    }

    /**
     * The RAG Engine bridge is on, and the engine is installed.
     */
    protected function ragEnabled(): bool
    {
        return config('ticketing.rag.enabled', false) === true && class_exists(RagEngine::class);
    }

    /**
     * Register an event subscriber's listeners on the dispatcher.
     *
     * The handlers are wrapped so a failure in a side-effect (SLA, routing) is
     * reported but never propagates back to the action that emitted the event —
     * a misconfigured rule must not fail (or half-complete) a ticket open.
     */
    protected function subscribe(Subscriber $subscriber): void
    {
        foreach ($subscriber->subscribe() as $event => $method) {
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
