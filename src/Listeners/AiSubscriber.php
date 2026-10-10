<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Actions\TakeOverTicket;
use Selli\Ticketing\Ai\AiRequestFactory;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Jobs\RunAiTurn;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Support\Ticketing;

/**
 * Wires the AI to the ticket conversation:
 *
 * - a customer's public message starts an AI turn (queued);
 * - an agent's public reply is a takeover: the AI stops on that ticket;
 * - a ticket resolved while the AI was handling it, with no human reply,
 *   counts as resolved by the AI.
 *
 * @internal
 */
class AiSubscriber implements Subscriber
{
    public function __construct(
        protected Container $container,
        protected AiRequestFactory $requests,
    ) {}

    public function onMessage(MessagePosted $event): void
    {
        $message = $event->message;

        if ($message->visibility !== MessageVisibility::Public || $message->source === MessageSource::Ai) {
            return;
        }

        $ticket = $event->ticket;
        $author = $message->author_id !== null ? $message->author : null;

        if ($author instanceof CanActOnTickets && $author instanceof Model && ! $this->requests->isRequester($ticket, $author)) {
            if ($ticket->ai_status === AiStatus::Active || $ticket->ai_status === AiStatus::Escalated) {
                $this->container->make(TakeOverTicket::class)->handle($ticket, $author, assign: false);
            }

            return;
        }

        $fromCustomer = $author instanceof Contact
            || ($author instanceof Model && $this->requests->isRequester($ticket, $author))
            || ($author === null && in_array($message->source, [MessageSource::Email, MessageSource::Widget], true));

        if ($fromCustomer && ($ticket->ai_status === null || $ticket->ai_status->answers())) {
            RunAiTurn::dispatchFor($ticket, $message);
        }
    }

    public function onResolved(TicketResolved $event): void
    {
        if ($event->ticket->ai_status !== AiStatus::Active) {
            return;
        }

        Ticketing::ticketModel()::query()->withoutTenancy()
            ->whereKey($event->ticket->getKey())
            ->where('ai_status', AiStatus::Active->value)
            ->update(['ai_status' => AiStatus::Resolved->value]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            MessagePosted::class => 'onMessage',
            TicketResolved::class => 'onResolved',
        ];
    }
}
