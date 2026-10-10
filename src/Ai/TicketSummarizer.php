<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Contracts\AiSettingsResolver;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\AiMode;
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

    /**
     * The summary text, or null (no summary, the AI is off for this ticket, or
     * the driver failed). No lock is held while the model works.
     */
    public function generate(Ticket $ticket): ?AiSummary
    {
        try {
            $settings = $this->container->make(AiSettingsResolver::class);

            // An escalation can be asked for with the AI off (by an agent, or
            // the requester over REST): then nothing goes to the provider.
            $tenant = $ticket->getAttribute($ticket->getTenantColumn());
            if ($settings->mode($tenant, $this->requests->typeKey($ticket), $ticket->channel) === AiMode::Off) {
                return null;
            }

            $request = $this->requests->forTicket($ticket, null, $settings->audience($ticket));
            $summary = $this->ai->driver()->summarize($request);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        return trim($summary->body) === '' ? null : $summary;
    }

    /**
     * Post a summary as an internal note.
     */
    public function post(Ticket $ticket, AiSummary $summary): TicketMessage
    {
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
