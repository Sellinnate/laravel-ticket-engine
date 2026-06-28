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
            '=', 'eq' => $this->looseEquals($actual, $expected),
            '!=', 'neq' => ! $this->looseEquals($actual, $expected),
            'gt' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a > $b),
            'gte' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a >= $b),
            'lt' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a < $b),
            'lte' => $this->compareNum($actual, $expected, fn (float $a, float $b): bool => $a <= $b),
            'in' => $this->inList($actual, $expected),
            'not_in' => ! $this->inList($actual, $expected),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            'contains' => $actual !== null && is_scalar($expected) && str_contains((string) $actual, (string) $expected),
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

    /**
     * Type-tolerant scalar equality: rule JSON usually stores values as strings,
     * so "30" must match the integer 30 and "true" the boolean true. Booleans
     * compare by truthiness, numerics numerically, everything else as strings.
     */
    protected function looseEquals(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        if (is_bool($a) || is_bool($b)) {
            return $this->toBool($a) === $this->toBool($b);
        }

        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        if ((is_scalar($a)) && (is_scalar($b))) {
            return (string) $a === (string) $b;
        }

        return false;
    }

    protected function inList(mixed $actual, mixed $expected): bool
    {
        if (! is_array($expected)) {
            return false;
        }

        foreach ($expected as $candidate) {
            if ($this->looseEquals($actual, $candidate)) {
                return true;
            }
        }

        return false;
    }

    protected function toBool(mixed $value): bool
    {
        return is_bool($value) ? $value : (bool) filter_var($value, FILTER_VALIDATE_BOOL);
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
}
