<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\TicketMessage;

#[IsReadOnly]
class GetTicketTool extends TicketingTool
{
    protected string $name = 'get-ticket';

    protected string $description = 'Read one ticket and its latest messages (internal notes only for agents).';

    public function handle(Request $request): Response
    {
        $ticket = $this->ticket($request);
        $internal = Gate::forUser($this->user($request))->allows('viewInternal', $ticket);

        $messages = $ticket->messages()
            ->with('author')
            ->when(! $internal, fn ($query) => $query->where('visibility', MessageVisibility::Public->value))
            ->latest()
            ->limit(50)
            ->get()
            ->reverse()
            ->values();

        return Response::json(array_merge($this->summary($ticket), [
            'messages' => $messages->map(fn (TicketMessage $message): array => $this->message($message))->all(),
        ]));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Reference (e.g. SUP-2026-00042) or id.')->required(),
        ];
    }
}
