<?php

declare(strict_types=1);

namespace Selli\Ticketing\Routing;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Selli\Ticketing\Actions\AssignTicket;
use Selli\Ticketing\Data\AssignTicketData;
use Selli\Ticketing\Models\RoutingRule;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Evaluates the ordered, data-driven routing rules for a ticket and routes the
 * first match to its team/assignee/strategy. Conditions are versionable data,
 * not `if` statements in code.
 */
class RoutingEngine
{
    public function __construct(protected Container $container) {}

    public function route(Ticket $ticket, ?Model $actor = null): ?Ticket
    {
        $rule = $this->match($ticket);

        if ($rule === null) {
            return null;
        }

        $team = $rule->team_id !== null ? $this->team($rule->team_id) : null;
        $assignee = $this->ruleAssignee($rule);

        if ($team === null && $assignee === null) {
            return null;
        }

        return $this->container->make(AssignTicket::class)->handle(new AssignTicketData(
            ticket: $ticket,
            assignee: $assignee,
            team: $team,
            strategy: $rule->strategy,
            actor: $actor,
        ));
    }

    protected function match(Ticket $ticket): ?RoutingRule
    {
        $model = Ticketing::routingRuleModel();
        $tenantColumn = $ticket->getTenantColumn();
        $tenantValue = $ticket->getAttribute($tenantColumn);
        $allowShared = config('ticketing.tenancy.allow_shared', true) !== false;

        $rules = $model::query()
            ->withoutTenancy()
            ->where('is_active', true)
            ->where(function ($query) use ($tenantColumn, $tenantValue, $allowShared): void {
                $query->where($tenantColumn, $tenantValue);

                if ($allowShared) {
                    $query->orWhereNull($tenantColumn);
                }
            })
            ->orderBy('position')
            ->orderBy((new $model)->getKeyName())
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($ticket, $rule->conditions ?? [])) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * @param  list<array{field: string, operator?: string, value?: mixed}>  $conditions
     */
    protected function matches(Ticket $ticket, array $conditions): bool
    {
        foreach ($conditions as $condition) {
            $actual = $this->resolveField($ticket, $condition['field']);

            if (! $this->compare($actual, $condition['operator'] ?? '=', $condition['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    protected function resolveField(Ticket $ticket, string $field): mixed
    {
        if (str_starts_with($field, 'custom_fields.')) {
            return $ticket->customField(substr($field, strlen('custom_fields.')));
        }

        return match ($field) {
            'type' => $this->typeKey($ticket),
            'category' => $ticket->category,
            'priority' => $ticket->priority->value,
            'subject_type' => $ticket->subject_type,
            'status' => $ticket->status,
            default => null,
        };
    }

    protected function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            '=', '==' => $actual == $expected,
            '!=', '<>' => $actual != $expected,
            'in' => is_array($expected) && in_array($actual, $expected, false),
            'not_in' => is_array($expected) && ! in_array($actual, $expected, false),
            '>' => $actual > $expected,
            '>=' => $actual >= $expected,
            '<' => $actual < $expected,
            '<=' => $actual <= $expected,
            'contains' => $this->contains($actual, $expected),
            default => false,
        };
    }

    protected function contains(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, $actual, false);
        }

        return is_string($actual) && is_scalar($expected) && str_contains($actual, (string) $expected);
    }

    protected function typeKey(Ticket $ticket): ?string
    {
        $model = Ticketing::ticketTypeModel();
        $type = $model::query()->withoutTenancy()->whereKey($ticket->ticket_type_id)->first();

        return $type?->key;
    }

    protected function team(int|string $id): ?Team
    {
        $model = Ticketing::teamModel();

        $team = $model::query()->withoutTenancy()->find($id);

        return $team instanceof Team ? $team : null;
    }

    protected function ruleAssignee(RoutingRule $rule): ?Model
    {
        if ($rule->assignee_type === null || $rule->assignee_id === null) {
            return null;
        }

        /** @var class-string<Model>|null $class */
        $class = Relation::getMorphedModel($rule->assignee_type) ?? $rule->assignee_type;

        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }

        $assignee = $class::query()->find($rule->assignee_id);

        return $assignee instanceof Model ? $assignee : null;
    }
}
