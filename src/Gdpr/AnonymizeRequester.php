<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Contacts\ContactDirectory;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Events\RequesterAnonymized;
use Selli\Ticketing\Knowledge\KnowledgeBase;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;

/**
 * Scrubs the denormalised personal data the package stores for a requester (the
 * email channel's from/from-name on the requester's own messages), keeping the
 * tickets for statistics. The host's requester model is the host's to anonymise
 * — it listens to {@see RequesterAnonymized}. Each touched ticket gets an audit
 * entry, so the anonymisation itself is traceable.
 *
 * @internal
 */
class AnonymizeRequester
{
    public function __construct(
        protected AuditLogger $audit,
        protected ContactDirectory $contacts,
    ) {}

    public function handle(Model $requester): int
    {
        $label = (string) config('ticketing.gdpr.anonymized_label', '[anonymized]');
        $email = $requester instanceof CanRequestTickets ? $requester->requesterEmail() : null;
        // Match the normalisation the email channel stores from with (lowercased,
        // trimmed), so a "Jane@Example.test " value still matches.
        $email = is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;

        $touched = 0;

        DB::transaction(function () use ($requester, $email, $label, &$touched): void {
            foreach (RequesterTickets::query($requester)->cursor() as $ticket) {
                // The AI keeps a short excerpt of each customer question for the
                // knowledge-gap report: a copy of the requester's words.
                $scrubbedTurns = $this->scrubAiQuestions($ticket);
                $scrubbed = $this->scrubMessages($ticket, $requester, $email, $label) || $scrubbedTurns;

                // Every ticket of the person is audited — a durable marker that
                // keeps it out of copies made later (a search index) — but only
                // those where data was actually scrubbed are counted.
                $this->audit->record(
                    ticket: $ticket,
                    event: 'requester.anonymized',
                    context: ['requester_type' => $requester->getMorphClass(), 'scrubbed' => $scrubbed],
                );

                if ($scrubbed) {
                    $touched++;
                }
            }
        });

        // Feedback comments the person left on articles (their vote stays).
        Ticketing::kbArticleFeedbackModel()::query()->withoutTenancy()
            ->where('voter_key', KnowledgeBase::voterKey($requester))
            ->whereNotNull('comment')
            ->update(['comment' => null]);

        // A package contact is ours to anonymise too (a host model is the
        // host's job, via RequesterAnonymized). A verified host user's widget
        // conversations hold their words as well.
        if ($requester instanceof Contact) {
            $this->contacts->scrub($requester);
        } else {
            $this->contacts->scrubConversations(
                Ticketing::conversationModel()::query()->withoutTenancy()
                    ->where('requester_type', $requester->getMorphClass())
                    ->where('requester_id', (string) $requester->getKey())
                    ->pluck((new (Ticketing::conversationModel()))->getKeyName())
                    ->all(),
                $label,
            );
        }

        RequesterAnonymized::dispatch($requester, $touched);

        return $touched;
    }

    /**
     * The AI's question excerpts on the ticket, and on the conversation it was
     * escalated from.
     */
    protected function scrubAiQuestions(Ticket $ticket): bool
    {
        $conversations = Ticketing::conversationModel()::query()->withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->pluck((new (Ticketing::conversationModel()))->getKeyName())
            ->all();

        return Ticketing::aiTurnModel()::query()->withoutTenancy()
            ->where(function ($query) use ($ticket, $conversations): void {
                $query->where('ticket_id', $ticket->getKey());

                if ($conversations !== []) {
                    $query->orWhereIn('conversation_id', $conversations);
                }
            })
            ->whereNotNull('question')
            ->update(['question' => null]) > 0;
    }

    protected function scrubMessages(Ticket $ticket, Model $requester, ?string $email, string $label): bool
    {
        $relation = $ticket->messages()->withoutTenancy();

        if (RequesterTickets::softDeletes(Ticketing::ticketMessageModel())) {
            $relation->withTrashed();
        }

        $messages = $relation
            ->where(function (Builder $query) use ($requester, $email): void {
                $query->where(function (Builder $author) use ($requester): void {
                    $author->where('author_type', $requester->getMorphClass())
                        ->where('author_id', $requester->getKey());
                });

                if ($email !== null) {
                    $query->orWhere('meta->from', $email);
                }
            })
            ->get();

        $changedAny = false;

        foreach ($messages as $message) {
            /** @var TicketMessage $message */
            $meta = $message->meta ?? [];
            $changed = false;

            foreach (['from', 'from_name'] as $key) {
                if (array_key_exists($key, $meta) && $meta[$key] !== $label) {
                    $meta[$key] = $label;
                    $changed = true;
                }
            }

            if ($changed) {
                $message->meta = $meta;
                $message->save();
                $changedAny = true;
            }
        }

        return $changedAny;
    }
}
