<?php

declare(strict_types=1);

namespace Selli\Ticketing\Reporting;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Tenancy\TenantContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV downloads — rows are read lazily (by key, in chunks) and written
 * straight to the output, so an export of any size runs in constant memory.
 * No spreadsheet dependency: CSV opens everywhere. Cells that a spreadsheet
 * would run as a formula (=, +, -, @, tab, CR) are prefixed with a quote.
 */
class CsvExport
{
    /**
     * @var list<string>
     */
    public const TICKET_COLUMNS = [
        'id', 'reference', 'title', 'status', 'priority', 'channel', 'category', 'ticket_type_id', 'team_id',
        'assignee_type', 'assignee_id', 'created_at', 'first_response_at', 'resolved_at', 'closed_at',
        'reopened_count', 'ai_status', 'escalation_reason',
    ];

    /**
     * @param  Builder<Ticket>  $query
     * @param  list<string>|null  $columns
     */
    public static function tickets(Builder $query, ?array $columns = null, string $filename = 'tickets.csv'): StreamedResponse
    {
        $columns ??= self::TICKET_COLUMNS;
        $key = $query->getModel()->getKeyName();

        // The response streams after this call returns, maybe outside the
        // tenant it was asked in: every chunk is read in that tenant.
        $context = app(TenantContext::class);
        $tenant = $context->current();

        return self::stream($columns, (function () use ($query, $columns, $key, $context, $tenant): \Generator {
            $last = null;

            do {
                $chunk = $context->forTenant($tenant, fn () => (clone $query)->reorder()->forPageAfterId(500, $last, $key)->get());

                foreach ($chunk as $ticket) {
                    yield array_map(fn (string $column): mixed => $ticket->getAttribute($column), $columns);
                    $last = $ticket->getKey();
                }
            } while ($chunk->count() === 500);
        })(), $filename);
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<array-key, mixed>>  $rows  each keyed by the header names, or a list in header order
     */
    public static function rows(array $header, iterable $rows, string $filename): StreamedResponse
    {
        return self::stream($header, (function () use ($header, $rows): \Generator {
            foreach ($rows as $row) {
                yield array_is_list($row) ? $row : array_map(fn (string $column): mixed => $row[$column] ?? null, $header);
            }
        })(), $filename);
    }

    /**
     * @param  list<string>  $header
     * @param  iterable<array<array-key, mixed>>  $rows
     */
    protected static function stream(array $header, iterable $rows, string $filename): StreamedResponse
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'export.csv';

        return new StreamedResponse(function () use ($header, $rows): void {
            $output = fopen('php://output', 'wb');

            if ($output === false) {
                return;
            }

            fputcsv($output, $header, escape: '');

            foreach ($rows as $row) {
                fputcsv($output, array_map(self::cell(...), $row), escape: '');
            }

            fclose($output);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    protected static function cell(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value; // a negative number is not a formula
        }

        $value = match (true) {
            $value === null => '',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };

        // CSV injection: never let a spreadsheet evaluate a cell.
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
