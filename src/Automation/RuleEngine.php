<?php

declare(strict_types=1);

namespace Selli\Ticketing\Automation;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Models\AutomationRule;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * The data-driven automation engine. For a trigger event it loads the ticket's
 * tenant's active rules (in priority order), evaluates each rule's conditions
 * and runs its actions. A re-entrancy depth guard stops a rule whose action
 * re-fires a trigger from cascading without bound.
 */
class RuleEngine
{
    protected int $depth = 0;

    public function __construct(
        protected ConditionEvaluator $conditions,
        protected ActionRunner $actions,
        protected TenantContext $tenant,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function run(string $eventKey, Ticket $ticket, ?Model $actor = null, array $context = []): void
    {
        $maxDepth = (int) config('ticketing.automation.max_depth', 5);

        if ($this->depth >= $maxDepth) {
            // A rule action re-fired a trigger too many times — stop the cascade.
            return;
        }

        $tenantValue = $ticket->getAttribute($ticket->getTenantColumn());

        // Evaluate rules AND run actions under the ticket's tenant so a queue/CLI
        // flow with a different ambient tenant still sees the right rules.
        $this->tenant->forTenant($tenantValue, function () use ($eventKey, $ticket, $actor): void {
            $rules = $this->rulesFor($eventKey);

            if ($rules->isEmpty()) {
                return;
            }

            $this->depth++;

            try {
                foreach ($rules as $rule) {
                    try {
                        $current = $ticket->fresh() ?? $ticket;

                        if (! $this->conditions->matches($current, $rule->conditions ?? [], $rule->match)) {
                            continue;
                        }

                        $this->actions->run($current, $rule->actions ?? [], $actor, $eventKey);

                        if ($rule->stop_processing) {
                            break;
                        }
                    } catch (\Throwable $exception) {
                        // Isolate a misconfigured or failing rule: report it and
                        // carry on, so one bad rule can't abort the rest (or the
                        // operation that emitted the event).
                        report($exception);
                    }
                }
            } finally {
                $this->depth--;
            }
        });
    }

    /**
     * @return Collection<int, AutomationRule>
     */
    protected function rulesFor(string $eventKey): Collection
    {
        /** @var Collection<int, AutomationRule> $rules */
        $rules = Ticketing::automationRuleModel()::query()->forTrigger($eventKey)->get();

        return $rules;
    }
}
