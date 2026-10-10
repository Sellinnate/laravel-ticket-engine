<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Selli\Ticketing\Support\Ticketing;

#[IsDestructive]
class TransitionTicketTool extends TicketingTool
{
    protected string $name = 'transition-ticket';

    protected string $description = 'Move a ticket through its workflow (e.g. resolve, close, reopen). Changes what the customer sees: requires "confirm": true.';

    public function handle(Request $request): Response
    {
        $ticket = $this->ticket($request, 'transition');
        $data = $request->validate([
            'transition' => ['required', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:20000'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        if (($data['confirm'] ?? false) !== true) {
            throw ValidationException::withMessages(['confirm' => "Set \"confirm\": true to apply [{$data['transition']}] to {$ticket->reference}."]);
        }

        $ticket = $this->attempt(fn () => app(Ticketing::class)->transition(
            $ticket,
            (string) $data['transition'],
            $this->user($request),
            isset($data['note']) ? (string) $data['note'] : null,
        ));

        return Response::json($this->summary($ticket));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Reference or id.')->required(),
            'transition' => $schema->string()->description('Transition name of the ticket type workflow (resolve, close, reopen…).')->required(),
            'note' => $schema->string()->description('Resolution note, when the workflow asks for one.'),
            'confirm' => $schema->boolean()->description('Must be true: the change is visible to the customer.')->required(),
        ];
    }
}
