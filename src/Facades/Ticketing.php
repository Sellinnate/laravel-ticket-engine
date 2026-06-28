<?php

declare(strict_types=1);

namespace Selli\Ticketing\Facades;

use Illuminate\Support\Facades\Facade;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;

/**
 * @method static \Selli\Ticketing\Models\Ticket open(string $type, string $title, mixed $requester = null, ?Priority $priority = null, mixed $subject = null, ?string $category = null, array<string, mixed> $attributes = [])
 * @method static \Selli\Ticketing\Support\PendingTicket for(mixed $target)
 * @method static \Selli\Ticketing\Models\TicketMessage postMessage(\Selli\Ticketing\Models\Ticket $ticket, mixed $author, string $body, MessageVisibility $visibility = MessageVisibility::Public, array<string, mixed> $meta = [])
 * @method static \Selli\Ticketing\Models\Ticket transition(\Selli\Ticketing\Models\Ticket $ticket, string $transition, ?\Illuminate\Database\Eloquent\Model $actor = null, ?string $note = null, array<string, mixed> $params = [])
 * @method static \Selli\Ticketing\Models\Ticket assign(\Selli\Ticketing\Models\Ticket $ticket, ?\Illuminate\Database\Eloquent\Model $assignee = null, ?\Selli\Ticketing\Models\Team $team = null, ?string $strategy = null, ?\Illuminate\Database\Eloquent\Model $actor = null)
 *
 * @see \Selli\Ticketing\Support\Ticketing
 */
class Ticketing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Selli\Ticketing\Support\Ticketing::class;
    }
}
