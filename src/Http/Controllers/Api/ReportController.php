<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Reporting\Metrics;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Support KPIs and CSV exports over the API, for agents. Every endpoint takes
 * the period (`from`, `to`: ISO dates or date-times, default the last 30 days)
 * and the ticket listing filters (channel, type, team, assignee…).
 *
 * @internal
 */
class ReportController extends Controller
{
    /** GET /reports/metrics — every KPI of the period. */
    public function metrics(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->for($request)->summary()]);
    }

    /** GET /reports/breakdown/{dimension}[?format=csv] — per agent, team, channel, category, priority or type. */
    public function breakdown(Request $request, string $dimension): JsonResponse|StreamedResponse
    {
        if (! array_key_exists($dimension, Metrics::DIMENSIONS)) {
            throw ValidationException::withMessages(['dimension' => 'The dimension must be one of: '.implode(', ', array_keys(Metrics::DIMENSIONS)).'.']);
        }

        $metrics = $this->for($request);

        return $request->query('format') === 'csv'
            ? $metrics->exportBreakdown($dimension)
            : response()->json(['data' => $metrics->breakdown($dimension)]);
    }

    /** GET /reports/tickets — the tickets created in the period, as CSV. */
    public function tickets(Request $request): StreamedResponse
    {
        return $this->for($request)->exportTickets();
    }

    protected function for(Request $request): Metrics
    {
        $this->authorizeAny($request->user(), 'viewReports');

        $from = $this->date($request->query('from'), 'from');
        $to = $this->date($request->query('to'), 'to', endOfDay: true);
        $to ??= Carbon::now();
        $from ??= $to->copy()->subDays(30)->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The period must start before it ends.']);
        }

        /** @var array<string, mixed> $filters */
        $filters = $request->query();
        unset($filters['from'], $filters['to'], $filters['format']);

        return Ticketing::metrics()->between($from, $to)->filter($filters, $this->actor($request));
    }

    protected function date(mixed $value, string $field, bool $endOfDay = false): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        // A bare date covers its whole day; offsets are converted to the
        // application timezone the timestamps are stored in.
        return TicketQuery::parseDate($value, $endOfDay)
            ?? throw ValidationException::withMessages([$field => "The {$field} must be a date (YYYY-MM-DD) or an ISO date-time."]);
    }
}
