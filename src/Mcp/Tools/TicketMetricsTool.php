<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
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

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $to = isset($data['to']) ? Carbon::parse((string) $data['to']) : Carbon::now();
        $from = isset($data['from']) ? Carbon::parse((string) $data['from']) : $to->copy()->subDays(30)->startOfDay();

        /** @var array<string, mixed> $filters */
        $filters = Arr::only($request->all(), ['channel', 'type', 'team', 'priority', 'category', 'assignee']);

        return Response::json(app(Ticketing::class)->metrics()->between($from, $to)->filter($filters, $user)->summary());
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
