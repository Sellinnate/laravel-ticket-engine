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
     * `avg(<expression>)` with a fractional result everywhere (SQL Server keeps
     * the integer type of its argument, so the value is cast first).
     */
    public static function average(Expression $expression): Expression
    {
        return self::fragment(fn (Grammar $grammar): string => $grammar instanceof SqlServerGrammar
            ? 'avg(cast('.$expression->getValue($grammar).' as float))'
            : 'avg('.$expression->getValue($grammar).')');
    }

    /**
     * An expression with a column alias, for select lists.
     */
    public static function alias(Expression $expression, string $alias): Expression
    {
        return self::fragment(fn (Grammar $grammar): string => $expression->getValue($grammar).' as '.$grammar->wrap($alias));
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
     * Case variants of a search term for LIKE matching. LOWER() folds only
     * ASCII on SQLite (no ICU), so a non-ASCII term is also tried as typed, in
     * lower case, capitalised and in upper case — covering the spellings text
     * actually uses. ASCII terms need no variants.
     *
     * @return list<string>
     */
    public static function caseVariants(string $term): array
    {
        if (preg_match('/[^\x00-\x7F]/', $term) !== 1) {
            return [];
        }

        $lower = mb_strtolower($term);

        return array_values(array_unique([
            $term,
            $lower,
            mb_strtoupper(mb_substr($lower, 0, 1)).mb_substr($lower, 1),
            mb_strtoupper($term),
        ]));
    }

    /**
     * A relevance score: for every term and column, the column's weight when
     * LOWER(column) contains the term. Terms are embedded as literals, so they
     * must be letters and digits only (anything else is refused) — which makes
     * the literal impossible to break out of on any engine.
     *
     * @param  array<string, int>  $weights  column => weight
     * @param  list<string>  $terms  lower-cased letters/digits
     */
    public static function keywordScore(array $weights, array $terms): Expression
    {
        foreach ($terms as $term) {
            if (preg_match('/^[\p{L}\p{N}]+$/u', $term) !== 1) {
                throw new \InvalidArgumentException('Keyword terms may only contain letters and digits.');
            }
        }

        return self::fragment(function (Grammar $grammar) use ($weights, $terms): string {
            $parts = [];

            foreach ($terms as $term) {
                foreach ($weights as $column => $weight) {
                    $parts[] = 'CASE WHEN LOWER('.$grammar->wrap($column).") LIKE '%".$term."%' THEN ".(int) $weight.' ELSE 0 END';
                }
            }

            return $parts === [] ? '0' : '('.implode(' + ', $parts).')';
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
