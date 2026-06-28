<?php

declare(strict_types=1);

namespace Selli\Ticketing\Automation;

use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Support\Ticketing;

/**
 * Evaluates a rule's data-driven conditions against a ticket. Fields and
 * operators are allow-listed: an unknown field or operator throws (fail closed)
 * rather than silently matching, so a misconfigured rule never fires blindly.
 */
class ConditionEvaluator
{
    /**
     * Whether the ticket satisfies the conditions. $match is "all" (AND, the
     * default) or "any" (OR). An empty condition set always matches.
     *
     * @param  array<int, array<string, mixed>>  $conditions
     */
    public function matches(Ticket $ticket, array $conditions, string $match = 'all'): bool
    {
        if ($conditions === []) {
            return true;
        }

        $any = $match === 'any';

        foreach ($conditions as $condition) {
            $result = $this->evaluate($ticket, $condition);

            if ($any && $result) {
                return true;
            }

            if (! $any && ! $result) {
                return false;
            }
        }

        return ! $any;
    }

    /**
     * @param  array<string, mixed>  $condition
     */
    protected function evaluate(Ticket $ticket, array $condition): bool
    {
        $field = is_string($condition['field'] ?? null) ? $condition['field'] : '';
        $operator = is_string($condition['operator'] ?? null) ? $condition['operator'] : '';
        $expected = $condition['value'] ?? null;

        $actual = $this->fieldValue($ticket, $field);

        return match ($operator) {
            '=', 'eq' => $this->scalar($actual) === $this->scalar($expected),
            '!=', 'neq' => $this->scalar($actual) !== $this->scalar($expected),
            'gt' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a > $b),
            'gte' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a >= $b),
            'lt' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a < $b),
            'lte' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a <= $b),
            'in' => in_array($this->scalar($actual), $this->scalarList($expected), true),
            'not_in' => ! in_array($this->scalar($actual), $this->scalarList($expected), true),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            'contains' => $actual !== null && str_contains((string) $actual, (string) $expected),
            default => throw new InvalidConfigurationException("Unknown automation condition operator [{$operator}]."),
        };
    }

    protected function fieldValue(Ticket $ticket, string $field): mixed
    {
        return match ($field) {
            'priority' => $ticket->priority->value,
            'priority_name' => strtolower($ticket->priority->name),
            'status' => $ticket->status,
            'category' => $ticket->category,
            'type' => $this->typeKey($ticket),
            'team_id' => $ticket->team_id,
            'assignee_id' => $ticket->assignee_id,
            'assignee_type' => $ticket->assignee_type,
            'reopened_count' => $ticket->reopened_count,
            'is_assigned' => $ticket->assignee_id !== null,
            default => throw new InvalidConfigurationException("Unknown automation condition field [{$field}]."),
        };
    }

    protected function typeKey(Ticket $ticket): ?string
    {
        if ($ticket->ticket_type_id === null) {
            return null;
        }

        $type = Ticketing::ticketTypeModel()::query()->withoutTenancy()
            ->whereKey($ticket->ticket_type_id)
            ->first();

        return is_string($type?->key) ? $type->key : null;
    }

    protected function scalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) || $value === null ? $value : null;
    }

    /**
     * Numeric comparison that fails closed: a non-numeric (or null) operand never
     * matches, rather than being coerced to 0.
     *
     * @param  callable(float, float): bool  $op
     */
    protected function compareNum(mixed $a, mixed $b, callable $op): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return $op((float) $a, (float) $b);
    }

    /**
     * @return list<string|int|float|bool|null>
     */
    protected function scalarList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_map(fn (mixed $v): string|int|float|bool|null => $this->scalar($v), array_values($value));
    }
}
