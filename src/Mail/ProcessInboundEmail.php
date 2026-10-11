<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Selli\Ticketing\Actions\AddAttachment;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Contracts\InboundMailRouter;
use Selli\Ticketing\Contracts\InboundRequesterResolver;
use Selli\Ticketing\Data\AddAttachmentData;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\InboundOutcome;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Enums\TicketChannel;
use Selli\Ticketing\Events\ParticipantAdded;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Models\TicketParticipant;
use Selli\Ticketing\Support\MailThreadToken;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Turns a normalised {@see InboundEmail} into a ticket or a reply.
 *
 * Threading is layered: the +t_ reply-address token is primary, then the
 * In-Reply-To / References headers (stored Message-IDs and our own signed
 * ones), then the ticket reference in the subject ("[SUP-2026-00042]") — the
 * last two only for a sender already on the ticket — and failing all of them a
 * new ticket via the configured route. The method is loop- and duplicate-safe:
 * automated mail (auto-replies, bounces, no-reply senders, our own mail) is
 * dropped, a sender is rate limited, and the same Message-ID is never ingested
 * twice. Quoted history and signatures are cut from the stored body (the
 * original stays in `meta.original_body`); Cc addresses become `cc`
 * participants.
 *
 * @internal
 */
class ProcessInboundEmail
{
    public function __construct(
        protected InboundMailRouter $router,
        protected InboundRequesterResolver $requesters,
        protected TenantContext $tenant,
        protected PostMessage $postMessage,
        protected AddAttachment $addAttachment,
        protected ReplyParser $parser,
    ) {}

    public function handle(InboundEmail $email): ?TicketMessage
    {
        return $this->ingest($email)->message;
    }

    /**
     * Ingest an email and say what became of it — a poller acknowledges the
     * email unless it is worth retrying (rate limited).
     */
    public function ingest(InboundEmail $email): InboundResult
    {
        if (config('ticketing.mail.inbound.enabled', false) !== true) {
            return new InboundResult(InboundOutcome::Disabled);
        }

        // Anti-loop: never let an autoresponder, a bounce, a no-reply sender or
        // our own notification coming back open a ticket or reply.
        if ($email->isAutomated() || $this->isOwnMail($email)) {
            return new InboundResult(InboundOutcome::Ignored);
        }

        // The claim key: hashed when longer than the column (a client may send
        // a Message-ID of any length).
        $messageId = $email->messageId === null ? '' : MailMessageIds::column($email->messageId);

        // Cheap pre-check (a read) so an obvious redelivery is dropped BEFORE the
        // rate limiter, never burning the sender's flood-control slot. The
        // authoritative dedupe is the unique claim insert inside the transaction.
        if ($messageId !== '' && $this->claimed($messageId)) {
            return new InboundResult(InboundOutcome::Duplicate);
        }

        // A rate-limited NEW message is dropped here, before any claim, so a
        // later retry (once the burst subsides) can still be ingested. The slot
        // is consumed only after a successful commit (see below), so a
        // rolled-back/dropped email never counts against the sender.
        if ($this->rateLimitExceeded($email)) {
            return new InboundResult(InboundOutcome::RateLimited);
        }

        // Ingest the email and record the dedupe claim in ONE transaction, so the
        // claim, the ticket and the message commit (or roll back) together:
        //  - a throw rolls EVERYTHING back, leaving no orphan claim or empty
        //    ticket, so a provider retry reprocesses cleanly;
        //  - a successful commit persists the claim as the permanent dedupe
        //    record, making redelivery idempotent;
        //  - a concurrent same-id delivery serialises on the unique index.
        DB::beginTransaction();

        try {
            // Lost a concurrent race for this id (the other delivery's claim
            // committed first) → nothing to do.
            if ($messageId !== '' && ! $this->claim($messageId)) {
                DB::rollBack();

                return new InboundResult(InboundOutcome::Duplicate);
            }

            $message = $this->process($email);

            // Unroutable / unknown type: nothing was ingested, so roll the claim
            // back too — never persist a claim for an email we didn't record.
            if ($message === null) {
                DB::rollBack();

                return new InboundResult(InboundOutcome::Unroutable);
            }

            DB::commit();

            // Count the slot only now — a committed ingest, not a failed attempt.
            $this->recordRateHit($email);

            return new InboundResult(InboundOutcome::Ingested, $message);
        } catch (\Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }
    }

    /**
     * Claim a Message-ID via a unique insert. Returns false when it was already
     * claimed (duplicate / concurrent delivery) — the atomic dedupe primitive.
     */
    protected function claim(string $messageId): bool
    {
        return DB::table($this->dedupeTable())->insertOrIgnore([
            'message_id' => $messageId,
            'created_at' => now(),
        ]) > 0;
    }

    protected function claimed(string $messageId): bool
    {
        return DB::table($this->dedupeTable())->where('message_id', $messageId)->exists();
    }

    protected function dedupeTable(): string
    {
        $prefix = (string) config('ticketing.tables.prefix', '');

        return $prefix.(string) config('ticketing.tables.inbound_emails', 'ticketing_inbound_emails');
    }

    protected function process(InboundEmail $email): ?TicketMessage
    {
        // A token in the reply address authorizes threading on its own.
        $ticket = $this->threadByToken($email);

        // Header threading uses non-secret Message-IDs, so only accept it when the
        // sender already appears on the ticket — otherwise anyone who knows or
        // replays an archived Message-ID could inject a message into the thread.
        // Failing the check, the email falls through to opening a new ticket.
        if ($ticket === null) {
            $candidate = $this->threadByHeader($email);

            if ($candidate instanceof Ticket && $this->senderOnTicket($candidate, $email)) {
                $ticket = $candidate;
            }
        }

        // Last resort: a reply whose client dropped the headers still carries
        // the reference we put in the subject.
        $ticket ??= $this->threadBySubject($email);

        if ($ticket instanceof Ticket) {
            // Operate inside the ticket's tenant — an inbound webhook has no
            // ambient context — and resolve the requester there too.
            return $this->tenant->forTenant(
                $ticket->getAttribute($ticket->getTenantColumn()),
                fn (): TicketMessage => $this->record($ticket, $email, $this->requesters->resolve($email)),
            );
        }

        return $this->openAndRecord($email);
    }

    /**
     * Open a fresh ticket for an email that threads to nothing, using the route
     * for its recipient, and record the message. Returns null when the recipient
     * is unroutable or the type is invalid (the email is dropped, not half-built).
     */
    protected function openAndRecord(InboundEmail $email): ?TicketMessage
    {
        $route = $this->router->route($email);

        if (! $route instanceof MailRoute) {
            return null;
        }

        $title = trim($email->subject) !== '' ? $email->subject : '(no subject)';

        try {
            return $this->tenant->forTenant($route->tenant, function () use ($route, $title, $email): TicketMessage {
                // Resolve the requester INSIDE the tenant (a tenant-aware resolver
                // needs the context) and reuse it as both the ticket requester and
                // the message author, so a find-or-create resolver runs once.
                $requester = $this->requesters->resolve($email);

                $ticket = app(Ticketing::class)->open(type: $route->type, title: $title, requester: $requester, channel: TicketChannel::Email);

                return $this->record($ticket, $email, $requester);
            });
        } catch (TicketingException $exception) {
            // An unknown type / domain rejection must not 500 a webhook.
            report($exception);

            return null;
        }
    }

    protected function threadByToken(InboundEmail $email): ?Ticket
    {
        try {
            $ticketId = MailThreadToken::fromRecipients($email->recipients);
        } catch (InvalidConfigurationException $exception) {
            // No token secret configured → can't verify tokens. Fail closed to no
            // token threading (header/routing still apply) instead of 500ing.
            report($exception);

            return null;
        }

        if ($ticketId === null) {
            return null;
        }

        $ticket = Ticketing::ticketModel()::withoutTenancy()->find($ticketId);

        return $ticket instanceof Ticket ? $ticket : null;
    }

    /**
     * In-Reply-To names the DIRECT parent, so it's tried first; References is
     * ordered oldest→newest, so its newest (closest ancestor) is tried next.
     * Each id is resolved on its own — never a whereIn+latest() that could pick
     * the most recent of several matches over the actual parent. An id is
     * either a stored inbound Message-ID (indexed column) or one of the signed
     * ids our own notifications are sent with.
     */
    protected function threadByHeader(InboundEmail $email): ?Ticket
    {
        $candidates = array_merge([$email->inReplyTo], array_reverse($email->references));

        $seen = [];
        foreach ($candidates as $reference) {
            if (! is_string($reference) || ($id = $this->normaliseId($reference)) === '' || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;

            $ours = MailMessageIds::ticketFrom($id);

            if ($ours !== null) {
                $ticket = Ticketing::ticketModel()::withoutTenancy()->find($ours);
            } else {
                /** @var TicketMessage|null $message */
                $message = Ticketing::ticketMessageModel()::withoutTenancy()
                    ->where('email_message_id', MailMessageIds::column($id))
                    ->latest()
                    ->first();

                $ticket = $message?->ticket()->withoutTenancy()->first();
            }

            if ($ticket instanceof Ticket) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * A bracketed ticket reference in the subject, e.g. "Re: Printer
     * [SUP-2026-00042]" — in the routed tenant when the recipient has one, and
     * only when exactly one such ticket has the sender on it.
     */
    protected function threadBySubject(InboundEmail $email): ?Ticket
    {
        if (preg_match_all('/\[([A-Za-z0-9][A-Za-z0-9._\/-]{1,62})\]/', $email->subject, $matches) < 1) {
            return null;
        }

        $tenant = $this->router->route($email)?->tenant;
        $model = Ticketing::ticketModel();

        foreach (array_unique($matches[1]) as $reference) {
            $found = $model::query()->withoutTenancy()
                ->where('reference', $reference)
                ->when($tenant !== null, fn ($query) => $query->where((new $model)->getTenantColumn(), $tenant))
                ->limit(6)
                ->get();

            // More tickets than we are willing to check: ambiguous, fail closed.
            if ($found->count() > 5) {
                continue;
            }

            $candidates = $found->filter(fn (Ticket $ticket): bool => $this->senderOnTicket($ticket, $email));

            if ($candidates->count() === 1) {
                return $candidates->first();
            }
        }

        return null;
    }

    /**
     * Has this sender already appeared on the ticket — as the sender of an
     * earlier email, as a contact requester/participant (a widget visitor,
     * a Cc'd colleague), or as a host-model participant or requester subject
     * with this address? Gate for header and subject threading only.
     */
    protected function senderOnTicket(Ticket $ticket, InboundEmail $email): bool
    {
        $from = strtolower(trim($email->from));

        if ($from === '') {
            return false;
        }

        $wrote = Ticketing::ticketMessageModel()::withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('meta->from', $from)
            ->exists();

        if ($wrote) {
            return true;
        }

        $contact = new (Ticketing::contactModel());
        $tenant = $ticket->getAttribute($ticket->getTenantColumn());
        $contacts = $contact::query()->withoutTenancy()
            ->where($contact->getTenantColumn(), $tenant)
            ->where('email', $from)
            ->pluck($contact->getKeyName())
            ->map(fn (mixed $key): string => (string) $key)
            ->all();

        $isContact = $contacts !== [] && $ticket->participants()->withoutTenancy()
            ->where('participant_type', $contact->getMorphClass())
            ->whereIn('participant_id', $contacts)
            ->exists();

        return $isContact || $this->hostPersonOnTicket($ticket, $contact->getMorphClass(), $from);
    }

    /**
     * A host model on the ticket (a user who opened it, a colleague, an
     * agent) whose requester or agent address is the sender's.
     */
    protected function hostPersonOnTicket(Ticket $ticket, string $contactMorph, string $from): bool
    {
        $keys = [];

        foreach ($ticket->participants()->withoutTenancy()->where('participant_type', '!=', $contactMorph)->get(['participant_type', 'participant_id']) as $participant) {
            $keys[$participant->participant_type][] = $participant->participant_id;
        }

        // A ticket opened about its requester (Ticketing::for($user)).
        if ($ticket->subject_type !== null && $ticket->subject_id !== null && $ticket->subject_type !== $contactMorph) {
            $keys[$ticket->subject_type][] = $ticket->subject_id;
        }

        foreach ($keys as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_a($class, Model::class, true)) {
                continue;
            }

            // Unscoped: a host's own tenancy must not hide the person from a
            // queued or console run; only their address is compared.
            foreach ($class::query()->withoutGlobalScopes()->whereKey(array_values(array_unique($ids)))->get() as $person) {
                $addresses = [
                    $person instanceof CanRequestTickets ? $person->requesterEmail() : null,
                    $person instanceof CanActOnTickets ? $person->agentEmail() : null,
                ];

                foreach ($addresses as $address) {
                    if (is_string($address) && strtolower(trim($address)) === $from) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function record(Ticket $ticket, InboundEmail $email, ?Model $author): TicketMessage
    {
        $message = $this->append($ticket, $email, $author);
        $this->importAttachments($message, $email);
        $this->addCcParticipants($ticket, $email);

        return $message;
    }

    protected function append(Ticket $ticket, InboundEmail $email, ?Model $author): TicketMessage
    {
        $original = $email->body();
        $body = config('ticketing.mail.inbound.strip_quotes', true) === false ? $original
            : ($email->bodyFormat() === BodyFormat::Html ? $this->parser->html($original) : $this->parser->text($original));

        $message = $this->postMessage->handle(new PostMessageData(
            ticket: $ticket,
            author: $author,
            body: $body,
            visibility: MessageVisibility::Public,
            bodyFormat: $email->bodyFormat(),
            source: MessageSource::Email,
            meta: array_filter([
                'message_id' => $email->messageId !== null ? $this->normaliseId($email->messageId) : null,
                // Stored lower-cased so senderOnTicket() can match it.
                'from' => strtolower(trim($email->from)),
                'from_name' => $email->fromName,
                'in_reply_to' => $email->inReplyTo !== null ? $this->normaliseId($email->inReplyTo) : null,
                // What the sender's client sent, quotes and signature included.
                'original_body' => $body !== $original ? $original : null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ));

        if ($email->messageId !== null && ($id = MailMessageIds::column($email->messageId)) !== '') {
            $message->forceFill(['email_message_id' => $id])->saveQuietly();
        }

        return $message;
    }

    /**
     * Cc'd people follow the conversation as `cc` participants (contacts) —
     * never our own addresses nor the sender, and at most ten per email.
     */
    protected function addCcParticipants(Ticket $ticket, InboundEmail $email): void
    {
        if (config('ticketing.mail.inbound.cc_participants', true) === false || config('ticketing.contacts.inbound_email', true) === false) {
            return;
        }

        $from = strtolower(trim($email->from));
        $addresses = array_unique(array_map(fn (string $address): string => strtolower(trim($address)), $email->cc));
        $added = 0;

        foreach ($addresses as $address) {
            if ($added >= 10) {
                break;
            }

            if ($address === $from || filter_var($address, FILTER_VALIDATE_EMAIL) === false || $this->isOwnAddress($address)) {
                continue;
            }

            $contact = app(Ticketing::class)->contacts()->remember($address);
            $model = Ticketing::ticketParticipantModel();

            /** @var TicketParticipant $participant */
            $participant = $model::query()->withoutTenancy()->firstOrCreate(
                [
                    'ticket_id' => $ticket->getKey(),
                    'participant_type' => $contact->getMorphClass(),
                    'participant_id' => $contact->getKey(),
                    'role' => ParticipantRole::Cc->value,
                ],
                array_merge($ticket->tenantAttributes(), ['notify' => true]),
            );

            if ($participant->wasRecentlyCreated) {
                ParticipantAdded::dispatch($ticket, $participant);
            }

            $added++;
        }
    }

    /**
     * One of our addresses: a tagged reply address, the reply-to base, the
     * sender of our mail, a polled mailbox, an address an inbound route names
     * explicitly, or one listed in `mail.inbound.own_addresses`.
     */
    protected function isOwnAddress(string $address): bool
    {
        if (MailThreadToken::tokenFromAddress($address) !== null) {
            return true;
        }

        $outboundFrom = config('ticketing.mail.outbound.from');
        $own = [
            config('ticketing.mail.outbound.reply_to_base'),
            is_array($outboundFrom) ? ($outboundFrom['address'] ?? null) : null,
            config('mail.from.address'),
            ...(array) config('ticketing.mail.inbound.own_addresses', []),
        ];

        foreach ((array) config('ticketing.mail.inbound.mailboxes', []) as $mailbox) {
            $own[] = is_array($mailbox) ? ($mailbox['address'] ?? $mailbox['username'] ?? null) : null;
        }

        $own = array_map(fn (mixed $value): string => is_string($value) ? strtolower(trim($value)) : '', $own);

        foreach ((array) config('ticketing.mail.inbound.routes', []) as $route) {
            $match = is_array($route) ? ($route['match'] ?? null) : null;

            if (is_string($match) && str_contains($match, '@') && ! str_starts_with($match, '/')) {
                $own[] = strtolower(trim($match));
            }
        }

        return in_array($address, $own, true);
    }

    /**
     * One of our own notifications delivered back to us (an agent whose
     * address is the support inbox, a forwarding loop).
     */
    protected function isOwnMail(InboundEmail $email): bool
    {
        $headers = array_change_key_case($email->headers, CASE_LOWER);

        return isset($headers['x-ticketing-mailer'])
            || ($email->messageId !== null && MailMessageIds::ticketFrom($email->messageId) !== null);
    }

    protected function importAttachments(TicketMessage $message, InboundEmail $email): void
    {
        foreach ($email->attachments as $attachment) {
            // An anonymous temp file, removed by the OS when the handle closes.
            $handle = tmpfile();

            if ($handle === false) {
                continue;
            }

            fwrite($handle, $attachment['content']);
            $path = stream_get_meta_data($handle)['uri'] ?? null;

            if (! is_string($path)) {
                fclose($handle);

                continue;
            }

            try {
                $this->addAttachment->handle(new AddAttachmentData(
                    attachable: $message,
                    file: new UploadedFile($path, $attachment['filename'], $attachment['mime'], null, true),
                ));
            } catch (TicketingException $exception) {
                // A rejected attachment (bad mime/oversize) is skipped, never
                // failing the whole email.
                report($exception);
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Is the sender already at the limit? Read-only — the slot is consumed by
     * recordRateHit() AFTER a successful commit, so a rolled-back/dropped email
     * never burns a slot and can't block a legitimate retry.
     */
    protected function rateLimitExceeded(InboundEmail $email): bool
    {
        $max = $this->rateLimitMax();

        return $max > 0 && RateLimiter::tooManyAttempts($this->rateLimitKey($email), $max);
    }

    protected function recordRateHit(InboundEmail $email): void
    {
        if ($this->rateLimitMax() > 0) {
            RateLimiter::hit($this->rateLimitKey($email), 60);
        }
    }

    protected function rateLimitMax(): int
    {
        /** @var array{max_per_minute?: int} $config */
        $config = config('ticketing.mail.inbound.rate_limit', []);

        return (int) ($config['max_per_minute'] ?? 30);
    }

    protected function rateLimitKey(InboundEmail $email): string
    {
        return 'ticketing:inbound:'.hash('sha256', strtolower(trim($email->from)));
    }

    protected function normaliseId(string $id): string
    {
        return trim($id, " \t\n\r<>");
    }
}
