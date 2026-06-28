<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Selli\Ticketing\Actions\AddAttachment;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Contracts\InboundMailRouter;
use Selli\Ticketing\Contracts\InboundRequesterResolver;
use Selli\Ticketing\Data\AddAttachmentData;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Exceptions\TicketingException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\MailThreadToken;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Turns a normalised {@see InboundEmail} into a ticket or a reply.
 *
 * Threading is layered: the +t_ reply-address token is primary, then the
 * In-Reply-To / References headers against stored Message-IDs, then (failing
 * both) a new ticket via the configured route. The method is loop- and
 * duplicate-safe: automated mail is dropped, a sender is rate limited, and the
 * same Message-ID is never ingested twice.
 */
class ProcessInboundEmail
{
    public function __construct(
        protected InboundMailRouter $router,
        protected InboundRequesterResolver $requesters,
        protected TenantContext $tenant,
        protected PostMessage $postMessage,
        protected AddAttachment $addAttachment,
    ) {}

    public function handle(InboundEmail $email): ?TicketMessage
    {
        if (config('ticketing.mail.inbound.enabled', false) !== true) {
            return null;
        }

        // Anti-loop: never let an autoresponder/bulk message open or reply.
        if ($email->isAutoReply()) {
            return null;
        }

        if ($this->rateLimited($email)) {
            return null;
        }

        // Without a usable Message-ID (absent, or angle-brackets/whitespace only)
        // there's nothing to deduplicate on — process plainly rather than enter
        // the lock/dedupe path that would silently no-op on an empty id.
        $messageId = $email->messageId === null ? '' : $this->normaliseId($email->messageId);

        if ($messageId === '') {
            return $this->process($email);
        }

        // Serialise concurrent deliveries of the SAME Message-ID under an atomic
        // lock, then re-check inside it: the read-then-write alreadyIngested check
        // alone races (two webhooks both pass it and double-create). The lock
        // needs a shared cache store in production, as queues/sessions do.
        $lock = Cache::lock('ticketing:inbound:'.sha1($messageId), 15);
        $wait = (int) config('ticketing.mail.inbound.lock_wait_seconds', 8);

        try {
            return $lock->block($wait, function () use ($email, $messageId): ?TicketMessage {
                if ($this->alreadyIngested($messageId)) {
                    return null;
                }

                return $this->process($email);
            });
        } catch (LockTimeoutException) {
            // The holder may have crashed/stalled. Only drop if the message
            // actually landed; otherwise process without the lock so the mail is
            // not lost (the holder's lock TTL expires shortly either way).
            return $this->alreadyIngested($messageId) ? null : $this->process($email);
        }
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

                $ticket = app(Ticketing::class)->open(type: $route->type, title: $title, requester: $requester);

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
        $ticketId = MailThreadToken::fromRecipients($email->recipients);

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
     * the most recent of several matches over the actual parent.
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

            /** @var TicketMessage|null $message */
            $message = Ticketing::ticketMessageModel()::withoutTenancy()
                ->where('meta->message_id', $id)
                ->latest()
                ->first();

            $ticket = $message?->ticket()->withoutTenancy()->first();

            if ($ticket instanceof Ticket) {
                return $ticket;
            }
        }

        return null;
    }

    /**
     * Has this sender already appeared on the ticket (as the requester's opening
     * message or a prior reply)? Gate for header threading only.
     */
    protected function senderOnTicket(Ticket $ticket, InboundEmail $email): bool
    {
        $from = strtolower(trim($email->from));

        if ($from === '') {
            return false;
        }

        return Ticketing::ticketMessageModel()::withoutTenancy()
            ->where('ticket_id', $ticket->getKey())
            ->where('meta->from', $from)
            ->exists();
    }

    protected function record(Ticket $ticket, InboundEmail $email, ?Model $author): TicketMessage
    {
        $message = $this->append($ticket, $email, $author);
        $this->importAttachments($message, $email);

        return $message;
    }

    protected function append(Ticket $ticket, InboundEmail $email, ?Model $author): TicketMessage
    {
        return $this->postMessage->handle(new PostMessageData(
            ticket: $ticket,
            author: $author,
            body: $email->body(),
            visibility: MessageVisibility::Public,
            bodyFormat: $email->bodyFormat(),
            source: MessageSource::Email,
            meta: array_filter([
                'message_id' => $email->messageId !== null ? $this->normaliseId($email->messageId) : null,
                // Stored lower-cased so senderOnTicket() can match it.
                'from' => strtolower(trim($email->from)),
                'from_name' => $email->fromName,
                'in_reply_to' => $email->inReplyTo !== null ? $this->normaliseId($email->inReplyTo) : null,
            ], fn ($value): bool => $value !== null && $value !== ''),
        ));
    }

    protected function importAttachments(TicketMessage $message, InboundEmail $email): void
    {
        foreach ($email->attachments as $attachment) {
            $path = tempnam(sys_get_temp_dir(), 'ticketing-inbound');

            if ($path === false) {
                continue;
            }

            file_put_contents($path, $attachment['content']);

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
                @unlink($path);
            }
        }
    }

    protected function rateLimited(InboundEmail $email): bool
    {
        /** @var array{max_per_minute?: int} $config */
        $config = config('ticketing.mail.inbound.rate_limit', []);
        $max = (int) ($config['max_per_minute'] ?? 30);

        if ($max <= 0) {
            return false;
        }

        $key = 'ticketing:inbound:'.sha1(strtolower(trim($email->from)));

        if (RateLimiter::tooManyAttempts($key, $max)) {
            return true;
        }

        RateLimiter::hit($key, 60);

        return false;
    }

    protected function alreadyIngested(string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        return Ticketing::ticketMessageModel()::withoutTenancy()
            ->where('meta->message_id', $messageId)
            ->exists();
    }

    protected function normaliseId(string $id): string
    {
        return trim($id, " \t\n\r<>");
    }
}
