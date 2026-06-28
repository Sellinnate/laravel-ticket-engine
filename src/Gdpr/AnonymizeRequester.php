<?php

declare(strict_types=1);

namespace Selli\Ticketing\Gdpr;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Events\RequesterAnonymized;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\AuditLogger;

/**
 * Scrubs the denormalised personal data the package stores for a requester (the
 * email channel's from/from-name on the requester's own messages), keeping the
 * tickets for statistics. The host's requester model is the host's to anonymise
 * — it listens to {@see RequesterAnonymized}. Each touched ticket gets an audit
 * entry, so the anonymisation itself is traceable.
 */
class AnonymizeRequester
{
    public function __construct(protected AuditLogger $audit) {}

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
                if (! $this->scrubMessages($ticket, $requester, $email, $label)) {
                    continue;
                }

                // Audit (and count) only tickets where data was actually scrubbed.
                $this->audit->record(
                    ticket: $ticket,
                    event: 'requester.anonymized',
                    context: ['requester_type' => $requester->getMorphClass()],
                );

                $touched++;
            }
        });

        RequesterAnonymized::dispatch($requester, $touched);

        return $touched;
    }

    protected function scrubMessages(Ticket $ticket, Model $requester, ?string $email, string $label): bool
    {
        $messages = $ticket->messages()->withoutTenancy()
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
