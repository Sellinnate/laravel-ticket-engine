<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mcp\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Tool;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\Ticketing;

/**
 * Shared ground of the ticketing MCP tools: every tool acts as the MCP user,
 * reads through the tenant scope and authorizes through the package policies
 * — exactly like the REST API. Without an authenticated user nothing runs.
 * A refusal (AuthorizationException) or an invalid call (ValidationException)
 * goes back to the client as a tool error.
 */
abstract class TicketingTool extends Tool
{
    /**
     * The MCP user, or a refusal.
     *
     * @throws AuthorizationException
     */
    protected function user(Request $request): Model&Authenticatable
    {
        $user = $request->user();

        if (! $user instanceof Model) {
            throw new AuthorizationException('An authenticated user is required.');
        }

        return $user;
    }

    /**
     * A ticket of the current tenant by reference (SUP-2026-00042) or id,
     * after checking the ability.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    protected function ticket(Request $request, string $ability = 'view'): Ticket
    {
        $user = $this->user($request);
        $value = $request->get('ticket');
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            throw ValidationException::withMessages(['ticket' => 'The ticket reference is required.']);
        }

        $model = Ticketing::ticketModel();
        $ticket = $model::query()->where('reference', $value)->first();

        // An id only when it can be one in the configured key type (a bigint
        // column refuses a ULID-looking or oversized value).
        $isKey = config('ticketing.ids.type') === 'ulid'
            ? preg_match('/^[0-9A-Za-z]{26}$/', $value) === 1
            : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;

        if (! $ticket instanceof Ticket && $isKey) {
            $ticket = $model::query()->whereKey($value)->first();
        }

        // Unknown and forbidden look the same: no probing of other tickets.
        if (! $ticket instanceof Ticket || ! Gate::forUser($user)->allows('view', $ticket)) {
            throw ValidationException::withMessages(['ticket' => "Ticket [{$value}] not found."]);
        }

        if ($ability !== 'view' && ! Gate::forUser($user)->allows($ability, $ticket)) {
            throw new AuthorizationException('You are not allowed to do that on this ticket.');
        }

        return $ticket;
    }

    /**
     * @throws AuthorizationException
     */
    protected function authorize(Request $request, string $ability, mixed $arguments = []): void
    {
        if (! Gate::forUser($this->user($request))->allows($ability, $arguments)) {
            throw new AuthorizationException('You are not allowed to do that.');
        }
    }

    /**
     * Run a package action, turning a domain refusal (an unknown transition, a
     * guard) into a tool error.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     *
     * @throws ValidationException
     */
    protected function attempt(\Closure $callback): mixed
    {
        try {
            return $callback();
        } catch (TicketingException $exception) {
            throw ValidationException::withMessages(['ticket' => $exception->getMessage()]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function summary(Ticket $ticket): array
    {
        return [
            'id' => (string) $ticket->getKey(),
            'reference' => $ticket->reference,
            'title' => $ticket->title,
            'status' => $ticket->status,
            'priority' => $ticket->priority->label(),
            'channel' => $ticket->channel,
            'category' => $ticket->category,
            'assignee' => $ticket->assignee_id !== null ? ['type' => $ticket->assignee_type, 'id' => (string) $ticket->assignee_id] : null,
            'team_id' => $ticket->team_id !== null ? (string) $ticket->team_id : null,
            'ai_status' => $ticket->ai_status?->value,
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function message(TicketMessage $message): array
    {
        $author = $message->author_id !== null ? $message->author : null;
        $name = $author instanceof Model ? ($author->getAttribute('name') ?? $author->getAttribute('email')) : null;

        return [
            'id' => (string) $message->getKey(),
            'author' => is_string($name) ? $name : ($message->author_type === null ? 'system' : 'unknown'),
            'visibility' => $message->visibility->value,
            'source' => $message->source->value,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
