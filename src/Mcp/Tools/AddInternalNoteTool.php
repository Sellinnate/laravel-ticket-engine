<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Support\Ticketing;

class AddInternalNoteTool extends TicketingTool
{
    protected string $name = 'add-internal-note';

    protected string $description = 'Add an internal note to a ticket: only agents see it, the customer never does.';

    public function handle(Request $request): Response
    {
        $ticket = $this->ticket($request, 'commentInternal');
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $message = $this->attempt(fn () => app(Ticketing::class)->postMessage($ticket, $this->user($request), (string) $data['body'], MessageVisibility::Internal));

        return Response::json(['reference' => $ticket->reference, 'message' => $this->message($message)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Reference or id.')->required(),
            'body' => $schema->string()->required(),
        ];
    }
}
