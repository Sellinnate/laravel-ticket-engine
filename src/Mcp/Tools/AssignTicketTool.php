<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Models\Team;
use Selli\Ticketing\Support\Ticketing;

class AssignTicketTool extends TicketingTool
{
    protected string $name = 'assign-ticket';

    protected string $description = 'Assign a ticket to yourself ("me") and/or to a team (by key; its strategy picks an agent).';

    public function handle(Request $request): Response
    {
        $ticket = $this->ticket($request, 'assign');
        $user = $this->user($request);
        $data = $request->validate([
            'assignee' => ['nullable', 'in:me'],
            'team' => ['nullable', 'string', 'max:255'],
        ]);

        $team = null;
        if (isset($data['team'])) {
            $team = Ticketing::teamModel()::query()->where('key', (string) $data['team'])->where('is_active', true)->first();

            if (! $team instanceof Team) {
                throw ValidationException::withMessages(['team' => "Team [{$data['team']}] not found."]);
            }
        }

        $assignee = ($data['assignee'] ?? null) === 'me' && $user instanceof CanActOnTickets ? $user : null;

        if ($assignee === null && $team === null) {
            throw ValidationException::withMessages(['assignee' => 'Give "assignee": "me" and/or a team key.']);
        }

        $ticket = $this->attempt(fn () => app(Ticketing::class)->assign($ticket, $assignee, $team, actor: $user));

        return Response::json($this->summary($ticket));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'ticket' => $schema->string()->description('Reference or id.')->required(),
            'assignee' => $schema->string()->enum(['me']),
            'team' => $schema->string()->description('Team key.'),
        ];
    }
}
