<?php

declare(strict_types=1);

namespace Selli\Ticketing\Facades;

use Illuminate\Support\Facades\Facade;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\Priority;

/**
 * @method static \Selli\Ticketing\Models\Ticket open(string $type, string $title, mixed $requester = null, ?Priority $priority = null, mixed $subject = null, ?string $category = null, array<string, mixed> $attributes = [], \Selli\Ticketing\Enums\TicketChannel|string|null $channel = null)
 * @method static \Selli\Ticketing\Support\PendingTicket for(mixed $target)
 * @method static \Selli\Ticketing\Query\TicketQuery tickets()
 * @method static \Selli\Ticketing\Models\TicketMessage postMessage(\Selli\Ticketing\Models\Ticket $ticket, ?\Illuminate\Database\Eloquent\Model $author, string $body, MessageVisibility $visibility = MessageVisibility::Public, array<string, mixed> $meta = [])
 * @method static \Selli\Ticketing\Models\Ticket transition(\Selli\Ticketing\Models\Ticket $ticket, string $transition, ?\Illuminate\Database\Eloquent\Model $actor = null, ?string $note = null, array<string, mixed> $params = [])
 * @method static \Selli\Ticketing\Models\Ticket assign(\Selli\Ticketing\Models\Ticket $ticket, ?\Illuminate\Database\Eloquent\Model $assignee = null, ?\Selli\Ticketing\Models\Team $team = null, ?string $strategy = null, ?\Illuminate\Database\Eloquent\Model $actor = null)
 * @method static \Selli\Ticketing\Models\Ticket changePriority(\Selli\Ticketing\Models\Ticket $ticket, Priority $priority, ?\Illuminate\Database\Eloquent\Model $actor = null)
 * @method static \Selli\Ticketing\Models\TicketAttachment addAttachment(\Illuminate\Database\Eloquent\Model $attachable, \Illuminate\Http\UploadedFile $file, ?string $disk = null, ?\Illuminate\Database\Eloquent\Model $uploadedBy = null)
 * @method static \Selli\Ticketing\Models\Ticket tag(\Selli\Ticketing\Models\Ticket $ticket, list<string> $names)
 * @method static \Selli\Ticketing\Models\Ticket untag(\Selli\Ticketing\Models\Ticket $ticket, list<string> $names)
 * @method static \Selli\Ticketing\Models\Ticket applyMacro(\Selli\Ticketing\Models\Ticket $ticket, \Selli\Ticketing\Models\Macro $macro, ?\Illuminate\Database\Eloquent\Model $actor = null)
 * @method static \Selli\Ticketing\Models\Ticket merge(\Selli\Ticketing\Models\Ticket $target, iterable<\Selli\Ticketing\Models\Ticket> $sources, ?\Illuminate\Database\Eloquent\Model $actor = null)
 * @method static \Selli\Ticketing\Models\Ticket split(\Selli\Ticketing\Models\Ticket $source, list<int|string> $messageIds, ?string $title = null, ?\Illuminate\Database\Eloquent\Model $actor = null)
 * @method static \Selli\Ticketing\Models\SatisfactionRating requestCsat(\Selli\Ticketing\Models\Ticket $ticket, ?\Illuminate\Database\Eloquent\Model $actor = null)
 * @method static \Selli\Ticketing\Models\SatisfactionRating submitCsat(\Selli\Ticketing\Models\Ticket $ticket, int $rating, ?string $comment = null, ?\Illuminate\Database\Eloquent\Model $submittedBy = null)
 * @method static \Selli\Ticketing\Models\SatisfactionRating submitCsatByToken(string $token, int $rating, ?string $comment = null, ?\Illuminate\Database\Eloquent\Model $submittedBy = null)
 * @method static string csatToken(\Selli\Ticketing\Models\Ticket $ticket, ?int $ttl = null)
 * @method static \Selli\Ticketing\Models\TicketMessage|null receiveEmail(\Selli\Ticketing\Mail\InboundEmail $email)
 * @method static string|null replyAddressFor(\Selli\Ticketing\Models\Ticket $ticket)
 * @method static int anonymiseRequester(\Illuminate\Database\Eloquent\Model $requester)
 * @method static list<array<string, mixed>> exportRequesterData(\Illuminate\Database\Eloquent\Model $requester)
 * @method static \Selli\Ticketing\Models\Ticket takeOver(\Selli\Ticketing\Models\Ticket $ticket, \Illuminate\Database\Eloquent\Model $agent, bool $assign = true)
 * @method static \Selli\Ticketing\Models\Ticket escalate(\Selli\Ticketing\Models\Ticket $ticket, \Selli\Ticketing\Enums\EscalationReason $reason = \Selli\Ticketing\Enums\EscalationReason::Manual, ?\Illuminate\Database\Eloquent\Model $actor = null, bool $notifyCustomer = false)
 * @method static \Selli\Ticketing\Contacts\ContactDirectory contacts()
 * @method static \Selli\Ticketing\Knowledge\KnowledgeBase knowledge()
 * @method static \Selli\Ticketing\Conversations\ConversationService conversations()
 * @method static \Selli\Ticketing\Conversations\WidgetKeys widgetKeys()
 * @method static \Selli\Ticketing\Ai\AiManager ai()
 * @method static void useUlids(bool $enabled = true)
 * @method static class-string<\Selli\Ticketing\Models\Ticket> ticketModel()
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
