<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Illuminate\Http\UploadedFile;
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

        // Idempotent against a provider re-delivering the same webhook.
        if ($email->messageId !== null && $this->alreadyIngested($this->normaliseId($email->messageId))) {
            return null;
        }

        $ticket = $this->thread($email) ?? $this->open($email);

        if (! $ticket instanceof Ticket) {
            return null;
        }

        // Operate inside the ticket's own tenant — an inbound webhook has no
        // ambient user/tenant context, so it must be set explicitly.
        return $this->tenant->forTenant($ticket->getAttribute($ticket->getTenantColumn()), function () use ($ticket, $email): TicketMessage {
            $message = $this->append($ticket, $email);
            $this->importAttachments($message, $email);

            return $message;
        });
    }

    /**
     * Find the ticket this email belongs to: reply-address token first, then the
     * In-Reply-To / References headers. Loaded unscoped (an inbound webhook has
     * no tenant yet); the append then runs in the ticket's tenant.
     */
    protected function thread(InboundEmail $email): ?Ticket
    {
        $ticketId = MailThreadToken::fromRecipients($email->recipients);

        if ($ticketId !== null) {
            $ticket = Ticketing::ticketModel()::withoutTenancy()->find($ticketId);

            if ($ticket instanceof Ticket) {
                return $ticket;
            }
        }

        $references = [];
        foreach (array_merge([$email->inReplyTo], $email->references) as $reference) {
            if (is_string($reference) && $reference !== '') {
                $references[] = $this->normaliseId($reference);
            }
        }

        if ($references === []) {
            return null;
        }

        /** @var TicketMessage|null $message */
        $message = Ticketing::ticketMessageModel()::withoutTenancy()
            ->whereIn('meta->message_id', array_values(array_unique($references)))
            ->latest()
            ->first();

        $ticket = $message?->ticket()->withoutTenancy()->first();

        return $ticket instanceof Ticket ? $ticket : null;
    }

    /**
     * Open a fresh ticket for an email that threads to nothing, using the route
     * for its recipient. Returns null when the recipient is unroutable or the
     * configured type is invalid (the email is dropped, never half-created).
     */
    protected function open(InboundEmail $email): ?Ticket
    {
        $route = $this->router->route($email);

        if (! $route instanceof MailRoute) {
            return null;
        }

        $requester = $this->requesters->resolve($email);
        $title = trim($email->subject) !== '' ? $email->subject : '(no subject)';

        try {
            return $this->tenant->forTenant(
                $route->tenant,
                fn (): Ticket => app(Ticketing::class)->open(
                    type: (string) $route->type,
                    title: $title,
                    requester: $requester,
                ),
            );
        } catch (TicketingException $exception) {
            // An unknown type / domain rejection must not 500 a webhook.
            report($exception);

            return null;
        }
    }

    protected function append(Ticket $ticket, InboundEmail $email): TicketMessage
    {
        return $this->postMessage->handle(new PostMessageData(
            ticket: $ticket,
            author: $this->requesters->resolve($email),
            body: $email->body(),
            visibility: MessageVisibility::Public,
            bodyFormat: $email->bodyFormat(),
            source: MessageSource::Email,
            meta: array_filter([
                'message_id' => $email->messageId !== null ? $this->normaliseId($email->messageId) : null,
                'from' => $email->from,
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
