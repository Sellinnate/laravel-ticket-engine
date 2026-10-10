<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Query\TicketQuery;
use Selli\Ticketing\Support\Ticketing;

#[IsReadOnly]
class ListTicketsTool extends TicketingTool
{
    protected string $name = 'list-tickets';

    protected string $description = 'List the support tickets you can see, newest first. Agents see their tenant, customers their own tickets.';

    public function handle(Request $request): Response
    {
        $user = $this->user($request);
        $this->authorize($request, 'viewAny', Ticketing::ticketModel());

        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        /** @var array<string, mixed> $filters */
        $filters = Arr::only($request->all(), ['state', 'status', 'priority', 'type', 'category', 'channel', 'team', 'assignee', 'tag', 'q', 'created_from', 'created_to']);
        $query = TicketQuery::make()->filter($filters, $user);

        // Like the REST listing: a customer only ever sees their own tickets.
        if (! $user instanceof CanActOnTickets) {
            $query->involving($user);
        }

        // The page comes from the arguments, never from an ambient HTTP request.
        $number = (int) ($request->get('page') ?? 1);
        $page = $query->builder()->paginate((int) ($request->get('limit') ?? 20), ['*'], 'page', $number);

        return Response::json([
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'has_more' => $page->hasMorePages(),
            'tickets' => array_map(fn (Ticket $ticket): array => $this->summary($ticket), $page->items()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->enum(['open', 'closed', 'paused'])->description('Workflow meaning of the status.'),
            'status' => $schema->string()->description('Exact status (comma list for several).'),
            'priority' => $schema->string()->description('Priority value(s): 10 low, 20 normal, 30 high, 40 urgent (comma list).'),
            'type' => $schema->string()->description('Ticket type key(s).'),
            'channel' => $schema->string()->description('web, email, widget, chat, api… (comma list).'),
            'category' => $schema->string(),
            'team' => $schema->string()->description('Team key.'),
            'assignee' => $schema->string()->description('"me", "none" or "<morph type>:<id>".'),
            'tag' => $schema->string(),
            'q' => $schema->string()->description('Words in the reference, title or messages.'),
            'created_from' => $schema->string()->format('date'),
            'created_to' => $schema->string()->format('date'),
            'limit' => $schema->integer()->min(1)->max(50)->default(20),
            'page' => $schema->integer()->min(1)->default(1)->description('Next pages while "has_more" is true.'),
        ];
    }
}
