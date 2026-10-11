<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Selli\Ticketing\Support\Ticketing;

class ReplyToTicketTool extends TicketingTool
{
    protected string $name = 'reply-to-ticket';

    protected string $description = 'Post a public reply on a ticket: the customer sees it (and is notified).';

    public function handle(Request $request): Response
    {
        $ticket = $this->ticket($request, 'comment');
        $data = $request->validate(['body' => ['required', 'string', 'max:20000']]);

        $message = $this->attempt(fn () => app(Ticketing::class)->postMessage($ticket, $this->user($request), (string) $data['body']));

        return Response::json(['reference' => $ticket->reference, 'message' => $this->message($message)]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Reference or id.')->required(),
            'body' => $schema->string()->description('The reply, as the customer will read it.')->required(),
        ];
    }
}
