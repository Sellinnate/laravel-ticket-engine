<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;

/**
 * Writes an AI summary of a ticket as an internal note, for the agent who picks
 * it up. Never throws: a failing summary must not break an escalation.
 *
 * @internal
 */
class TicketSummarizer
{
    public function __construct(
        protected AiManager $ai,
        protected AiRequestFactory $requests,
        protected Container $container,
    ) {}

    public function summarize(Ticket $ticket): ?TicketMessage
    {
        try {
            $request = $this->requests->forTicket($ticket, null, $this->container->make(AiSettingsResolver::class)->audience($ticket));
            $summary = $this->ai->driver()->summarize($request);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        if (trim($summary->body) === '') {
            return null;
        }

        return $this->container->make(PostMessage::class)->handle(new PostMessageData(
            ticket: $ticket,
            author: null,
            body: $summary->body,
            visibility: MessageVisibility::Internal,
            source: MessageSource::Ai,
            meta: ['ai' => ['kind' => 'summary', 'model' => $summary->usage->model]],
        ));
    }
}
