<?php

declare(strict_types=1);

namespace Selli\Ticketing\Listeners;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Actions\TakeOverTicket;
use Selli\Ticketing\Ai\AiRequestFactory;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Enums\AiStatus;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Events\MessagePosted;
use Selli\Ticketing\Events\TicketReopened;
use Selli\Ticketing\Events\TicketResolved;
use Selli\Ticketing\Jobs\RunAiTurn;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Ticket;
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
            // The first agent reply is a takeover — also before the AI's first
            // turn completes (status still null), so a pending turn stands down.
            // Decided on the stored status (the event may carry a stale copy).
            $status = $this->storedStatus($ticket);

            if ($status === null || $status === AiStatus::Active || $status === AiStatus::Escalated) {
                $this->container->make(TakeOverTicket::class)->handle($ticket, $author, assign: false);
            }

            return;
        }

        $fromCustomer = $author instanceof Contact
            || ($author instanceof Model && $this->requests->isRequester($ticket, $author))
            || ($author === null && in_array($message->source, [MessageSource::Email, MessageSource::Widget], true));

        if (! $fromCustomer) {
            return;
        }

        // Read the AI status as stored now: an earlier listener for the same
        // message (e.g. reopen-on-reply) may have changed it.
        $status = $this->storedStatus($ticket);

        // No job for a ticket whose type or tenant has the AI off (the turn
        // re-checks the mode anyway: it may change before the job runs).
        if (($status === null || $status->answers()) && $this->mode($ticket) !== AiMode::Off) {
            RunAiTurn::dispatchFor($ticket, $message);
        }
    }

    protected function mode(Ticket $ticket): AiMode
    {
        return $this->container->make(AiSettingsResolver::class)->mode(
            $ticket->getAttribute($ticket->getTenantColumn()),
            $this->requests->typeKey($ticket),
            $ticket->channel,
        );
    }

    protected function storedStatus(Ticket $ticket): ?AiStatus
    {
        $status = Ticketing::ticketModel()::query()->withoutTenancy()->whereKey($ticket->getKey())->value('ai_status');

        return is_string($status) ? AiStatus::tryFrom($status) : null;
    }

    /**
     * A customer coming back to a ticket the AI had resolved is the AI's again.
     */
    public function onReopened(TicketReopened $event): void
    {
        Ticketing::ticketModel()::query()->withoutTenancy()
            ->whereKey($event->ticket->getKey())
            ->where('ai_status', AiStatus::Resolved->value)
            ->update(['ai_status' => AiStatus::Active->value]);
    }

    public function onResolved(TicketResolved $event): void
    {
        if ($event->ticket->ai_status !== AiStatus::Active) {
            return;
        }

        // Only when the AI actually answered the customer (a draft-only ticket
        // was resolved by a human).
        Ticketing::ticketModel()::query()->withoutTenancy()
            ->whereKey($event->ticket->getKey())
            ->where('ai_status', AiStatus::Active->value)
            ->where('ai_exchanges', '>', 0)
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
            TicketReopened::class => 'onReopened',
        ];
    }
}
