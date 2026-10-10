<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Support\Ticketing;

#[IsReadOnly]
class TicketMetricsTool extends TicketingTool
{
    protected string $name = 'ticket-metrics';

    protected string $description = 'Support KPIs for a period: volume, backlog, response and resolution times, SLA, reopen rate, AI, CSAT and unanswered questions.';

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $this->authorize($request, 'viewReports', Ticketing::ticketModel());

        $from = $this->date($request->get('from'), 'from');
        $to = $this->date($request->get('to'), 'to', endOfDay: true) ?? Carbon::now();
        $from ??= $to->copy()->subDays(30)->startOfDay();

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['from' => 'The period must start before it ends.']);
        }

        /** @var array<string, mixed> $filters */
        $filters = Arr::only($request->all(), ['channel', 'type', 'team', 'priority', 'category', 'assignee']);

        return Response::json(app(Ticketing::class)->metrics()->between($from, $to)->filter($filters, $user)->summary());
    }

    /**
     * A bare date covers its whole day; offsets are converted to the
     * application timezone.
     */
    protected function date(mixed $value, string $field, bool $endOfDay = false): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return TicketQuery::parseDate($value, $endOfDay)
            ?? throw ValidationException::withMessages([$field => "The {$field} must be a date (YYYY-MM-DD) or an ISO date-time."]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->format('date')->description('Start of the period (default: 30 days ago).'),
            'to' => $schema->string()->format('date')->description('End of the period (default: now).'),
            'channel' => $schema->string(),
            'type' => $schema->string(),
            'team' => $schema->string()->description('Team key.'),
            'priority' => $schema->string(),
            'category' => $schema->string(),
            'assignee' => $schema->string()->description('"me", "none" or "<morph type>:<id>".'),
        ];
    }
}
