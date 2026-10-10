<?php

declare(strict_types=1);

namespace Selli\Ticketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Selli\Ticketing\Ai\AiAssistant;
use Selli\Ticketing\Models\Conversation;
use Selli\Ticketing\Models\ConversationMessage;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Runs one AI turn off the request. Carries only ids (re-read under the right
 * tenant when the job runs), is dispatched after the surrounding transaction
 * commits, and is safe to retry: the turn is claimed once per message.
 *
 * @internal
 */
class RunAiTurn implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    public function __construct(
        public string $subject,
        public int|string $subjectId,
        public int|string $messageId,
        public int|string|null $tenantId,
    ) {
        $this->onConnection(config('ticketing.queue.connection'));
        $this->onQueue(config('ticketing.queue.queue'));
        $this->afterCommit();
    }

    /**
     * Queue the turn (ai.queue = true) or run it now.
     */
    public static function dispatchFor(Ticket|Conversation $subject, TicketMessage|ConversationMessage $message): void
    {
        $job = new self(
            $subject instanceof Ticket ? 'ticket' : 'conversation',
            $subject->getKey(),
            $message->getKey(),
            $subject->getAttribute($subject->getTenantColumn()),
        );

        config('ticketing.ai.queue', true) === false ? dispatch_sync($job) : dispatch($job);
    }

    public function handle(AiAssistant $assistant, TenantContext $tenant): void
    {
        $tenant->forTenant($this->tenantId, function () use ($assistant): void {
            if ($this->subject === 'ticket') {
                $ticket = Ticketing::ticketModel()::query()->withoutTenancy()->find($this->subjectId);
                $message = Ticketing::ticketMessageModel()::query()->withoutTenancy()->find($this->messageId);

                // A message split into another ticket meanwhile is not this
                // ticket's question any more (an agent is handling it).
                if ($ticket instanceof Ticket && $message instanceof TicketMessage && (string) $message->ticket_id === (string) $ticket->getKey()) {
                    $assistant->respondToTicket($ticket, $message, retry: $this->attempts() > 1);
                }

                return;
            }

            $conversation = Ticketing::conversationModel()::query()->withoutTenancy()->find($this->subjectId);
            $message = Ticketing::conversationMessageModel()::query()->withoutTenancy()->find($this->messageId);

            if ($conversation instanceof Conversation && $message instanceof ConversationMessage && (string) $message->conversation_id === (string) $conversation->getKey()) {
                $assistant->respondToConversation($conversation, $message, retry: $this->attempts() > 1);
            }
        });
    }
}
