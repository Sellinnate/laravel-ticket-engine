<?php

declare(strict_types=1);

namespace Selli\Ticketing\Database;

use Closure;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;

/**
 * Small, portable SQL fragments for the few places where SQLite, MySQL/MariaDB,
 * PostgreSQL and SQL Server disagree. Each fragment is rendered by the grammar
 * of the query it ends up in, so the same expression works on every driver.
 * Column names must be trusted identifiers (they are wrapped by the grammar,
 * never taken from user input).
 *
 * @internal
 */
final class SqlDialect
{
    /**
     * A column cast to a string, so a numeric key can be compared with the
     * package's string polymorphic id columns (PostgreSQL refuses
     * `bigint = varchar` without an explicit cast).
     */
    public static function castToString(string $column): Expression
    {
        return self::fragment(fn (Grammar $grammar): string => match (true) {
            $grammar instanceof MySqlGrammar => 'CAST('.$grammar->wrap($column).' AS CHAR)',
            $grammar instanceof SqlServerGrammar => 'CAST('.$grammar->wrap($column).' AS NVARCHAR(255))',
            default => 'CAST('.$grammar->wrap($column).' AS TEXT)',
        });
    }

    /**
     * The number of seconds between two timestamp columns (`$to - $from`),
     * NULL when either is NULL.
     */
    public static function secondsBetween(string $from, string $to): Expression
    {
        return self::fragment(function (Grammar $grammar) use ($from, $to): string {
            $from = $grammar->wrap($from);
            $to = $grammar->wrap($to);

            return match (true) {
                $grammar instanceof MySqlGrammar => "TIMESTAMPDIFF(SECOND, {$from}, {$to})",
                $grammar instanceof PostgresGrammar => "EXTRACT(EPOCH FROM ({$to} - {$from}))",
                $grammar instanceof SqlServerGrammar => "DATEDIFF_BIG(SECOND, {$from}, {$to})",
                default => "((julianday({$to}) - julianday({$from})) * 86400.0)",
            };
        });
    }

    /**
     * The calendar day (YYYY-MM-DD) of a timestamp column, for daily series.
     */
    public static function day(string $column): Expression
    {
        return self::fragment(function (Grammar $grammar) use ($column): string {
            $wrapped = $grammar->wrap($column);

            return match (true) {
                $grammar instanceof MySqlGrammar => "DATE_FORMAT({$wrapped}, '%Y-%m-%d')",
                $grammar instanceof PostgresGrammar => "TO_CHAR({$wrapped}, 'YYYY-MM-DD')",
                $grammar instanceof SqlServerGrammar => "CONVERT(VARCHAR(10), {$wrapped}, 23)",
                default => "strftime('%Y-%m-%d', {$wrapped})",
            };
        });
    }

    /**
     * @param  Closure(Grammar): string  $render
     */
    private static function fragment(Closure $render): Expression
    {
        return new class($render) implements Expression
        {
            /**
             * @param  Closure(Grammar): string  $render
             */
            public function __construct(private readonly Closure $render) {}

            public function getValue(Grammar $grammar): string
            {
                return ($this->render)($grammar);
            }
        };
    }
}
