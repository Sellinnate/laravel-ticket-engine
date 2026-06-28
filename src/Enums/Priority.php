<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Ticket priority levels, ordered from lowest to highest urgency.
 *
 * The integer-backed weight allows ordering and comparison in queries and
 * routing rules without coupling to label strings.
 */
enum Priority: int
{
    case Low = 10;
    case Normal = 20;
    case High = 30;
    case Urgent = 40;

    /**
     * Human readable label, useful for notifications and audit context.
     */
    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::Normal => 'Normal',
            self::High => 'High',
            self::Urgent => 'Urgent',
        };
    }

    /**
     * Whether this priority is at least as urgent as the given one.
     */
    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    /**
     * @return array<int, string> map of value => label
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
