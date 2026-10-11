<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Selli\Ticketing\Support\Ticketing;

#[IsIdempotent]
class TakeOverTicketTool extends TicketingTool
{
    protected string $name = 'take-over-ticket';

    protected string $description = 'Take a ticket over from the AI: it stops answering for good, and an unassigned ticket becomes yours.';

    public function handle(Request $request): Response
    {
        $ticket = $this->ticket($request, 'takeOver');

        $ticket = $this->attempt(fn () => app(Ticketing::class)->takeOver($ticket, $this->user($request), (bool) ($request->get('assign') ?? true)));

        return Response::json($this->summary($ticket));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Reference or id.')->required(),
            'assign' => $schema->boolean()->description('Assign an unassigned ticket to you (default true).'),
        ];
    }
}
