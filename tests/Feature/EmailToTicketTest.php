<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Selli\Ticketing\Contracts\InboundMailRouter;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketAttachment;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Support\MailThreadToken;

/**
 * @param  array<string, mixed>  $overrides
 */
function inbound(array $overrides = []): InboundEmail
{
    return InboundEmail::fromArray(array_merge([
        'from' => 'customer@example.test',
        'recipients' => ['support@example.test'],
        'subject' => 'Help me',
        'text' => 'My printer is broken.',
        'message_id' => '<msg-'.bin2hex(random_bytes(6)).'@example.test>',
    ], $overrides));
}

function enableInbound(string $match = 'support@example.test', int|string|null $tenant = null): void
{
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [
        ['match' => $match, 'tenant' => $tenant, 'type' => 'support'],
    ]);
}

it('signs and verifies a compact thread token and reads it from an address', function (): void {
    $token = MailThreadToken::issue(42);

    expect(MailThreadToken::verify($token))->toBe('42')
        ->and(MailThreadToken::verify($token.'x'))->toBeNull();

    $address = MailThreadToken::tagAddress('support@example.test', $token);
    expect($address)->toBe('support+t_'.$token.'@example.test')
        ->and(MailThreadToken::tokenFromAddress($address))->toBe($token)
        ->and(MailThreadToken::fromRecipients(['other@x.test', $address]))->toBe('42');
});

it('verifies token edge cases', function (): void {
    expect(MailThreadToken::verify('garbage'))->toBeNull()
        ->and(MailThreadToken::verify('a.b.c'))->toBeNull()
        ->and(MailThreadToken::tokenFromAddress('plain@example.test'))->toBeNull()
        ->and(MailThreadToken::tagAddress('not-an-address', 'tok'))->toBe('not-an-address')
        ->and(MailThreadToken::fromRecipients(['nobody@example.test']))->toBeNull();
});

it('routes by wildcard, regex and a stripped sub-address; drops unmatched', function (): void {
    $router = app(InboundMailRouter::class);

    config()->set('ticketing.mail.inbound.routes', [['match' => '/^sales@/', 'type' => 'incident']]);
    expect($router->route(inbound(['recipients' => ['sales@example.test']]))?->type)->toBe('incident')
        ->and($router->route(inbound(['recipients' => ['support@example.test']])))->toBeNull();

    config()->set('ticketing.mail.inbound.routes', [['match' => '*', 'type' => 'support']]);
    // A +t_ tagged recipient is matched on its base address.
    expect($router->route(inbound(['recipients' => ['support+t_abc@example.test']]))?->type)->toBe('support');

    // An empty match never matches.
    config()->set('ticketing.mail.inbound.routes', [['match' => '', 'type' => 'support']]);
    expect($router->route(inbound()))->toBeNull();
});

it('normalises an HTML-only body and recognises more auto-reply signals', function (): void {
    $html = InboundEmail::fromArray(['from' => 'a@b.test', 'html' => '<p>Hi</p>']);
    expect($html->body())->toBe('<p>Hi</p>')
        ->and($html->bodyFormat())->toBe(BodyFormat::Html);

    expect(InboundEmail::fromArray(['from' => 'a@b.test', 'headers' => ['Precedence' => 'bulk']])->isAutoReply())->toBeTrue()
        ->and(InboundEmail::fromArray(['from' => 'a@b.test', 'headers' => ['List-Id' => '<list.x>']])->isAutoReply())->toBeTrue()
        ->and(InboundEmail::fromArray(['from' => 'a@b.test', 'headers' => ['Auto-Submitted' => 'no']])->isAutoReply())->toBeFalse();
});

it('drops an email whose route names an unknown ticket type', function (): void {
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => '*', 'type' => 'no-such-type']]);

    expect(Ticketing::receiveEmail(inbound()))->toBeNull();
});

it('skips a rejected attachment without failing the email', function (): void {
    Storage::fake('local');
    enableInbound();
    config()->set('ticketing.attachments.allowed_mimes', ['application/pdf']);

    $message = Ticketing::receiveEmail(inbound([
        'text' => 'see attached',
        'attachments' => [
            ['filename' => 'evil.exe', 'mime' => 'application/x-msdownload', 'content' => 'MZ'],
        ],
    ]));

    // The message is still created; the disallowed attachment is just skipped.
    expect($message)->not->toBeNull()
        ->and(TicketAttachment::query()->where('attachable_id', $message->getKey())->where('attachable_type', $message->getMorphClass())->count())->toBe(0);
});

it('processes an email with a blank Message-ID without entering the dedupe path', function (): void {
    enableInbound();

    // An angle-brackets-only id normalises to empty: it must still open a ticket
    // (no usable id to dedupe on) rather than silently no-op in the lock path.
    $message = Ticketing::receiveEmail(inbound(['message_id' => '<>']));

    expect($message)->not->toBeNull()
        ->and($message->meta)->not->toHaveKey('message_id');
});

it('does nothing while the inbound channel is disabled', function (): void {
    config()->set('ticketing.mail.inbound.enabled', false);

    expect(Ticketing::receiveEmail(inbound()))->toBeNull();
});

it('opens a ticket from a new email', function (): void {
    enableInbound();

    $message = Ticketing::receiveEmail(inbound(['subject' => 'Broken printer']));

    expect($message)->not->toBeNull()
        ->and($message->source)->toBe(MessageSource::Email)
        ->and($message->body)->toBe('My printer is broken.')
        ->and($message->ticket->title)->toBe('Broken printer')
        ->and($message->meta['from'])->toBe('customer@example.test')
        ->and($message->meta)->toHaveKey('message_id');
});

it('drops an unroutable email (no matching route)', function (): void {
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => 'other@x.test', 'type' => 'support']]);

    expect(Ticketing::receiveEmail(inbound()))->toBeNull();
});

it('drops an auto-reply (anti-loop)', function (): void {
    enableInbound();

    $message = Ticketing::receiveEmail(inbound(['headers' => ['Auto-Submitted' => 'auto-replied']]));

    expect($message)->toBeNull();
});

it('threads a reply back to the ticket via the +t_ token', function (): void {
    enableInbound();
    config()->set('ticketing.mail.outbound.reply_to_base', 'support@example.test');

    $first = Ticketing::receiveEmail(inbound());
    $ticket = $first->ticket;

    $replyAddress = Ticketing::replyAddressFor($ticket);
    $reply = Ticketing::receiveEmail(inbound([
        'subject' => 'Re: Help me',
        'text' => 'Still broken.',
        'recipients' => [$replyAddress],
    ]));

    expect($reply->ticket->getKey())->toBe($ticket->getKey())
        ->and($ticket->fresh()->messages()->count())->toBe(2);
});

it('threads a reply via In-Reply-To against a stored Message-ID', function (): void {
    enableInbound();

    $first = Ticketing::receiveEmail(inbound(['message_id' => '<first@example.test>']));
    $ticket = $first->ticket;

    $reply = Ticketing::receiveEmail(inbound([
        'message_id' => '<second@example.test>',
        'in_reply_to' => '<first@example.test>',
        'text' => 'Replying.',
    ]));

    expect($reply->ticket->getKey())->toBe($ticket->getKey())
        ->and($ticket->fresh()->messages()->count())->toBe(2);
});

it('threads via a space-separated References header string', function (): void {
    enableInbound();

    $ticket = Ticketing::receiveEmail(inbound(['message_id' => '<root@example.test>']))->ticket;

    // References arrives as one header string with several ids.
    $reply = Ticketing::receiveEmail(inbound([
        'message_id' => '<later@example.test>',
        'references' => '<unknown@example.test> <root@example.test>',
        'text' => 'threaded',
    ]));

    expect($reply->ticket->getKey())->toBe($ticket->getKey())
        ->and($ticket->fresh()->messages()->count())->toBe(2);
});

it('drops token threading (not 500s) when no token secret is configured', function (): void {
    enableInbound();
    // No mail-token secret and no app key → MailThreadToken::secret() would throw.
    config()->set('ticketing.mail.token.secret', null);
    config()->set('app.key', '');

    // A +t_ tagged recipient must not crash the webhook; it falls back to routing
    // and opens a new ticket instead.
    $message = Ticketing::receiveEmail(inbound([
        'recipients' => ['support+t_anything@example.test'],
    ]));

    expect($message)->not->toBeNull();
});

it('prefers the In-Reply-To parent over a newer References match', function (): void {
    enableInbound();

    // Ticket A is the direct parent; ticket B is created later (newer row).
    $a = Ticketing::receiveEmail(inbound(['message_id' => '<a@example.test>']))->ticket;
    $b = Ticketing::receiveEmail(inbound(['from' => 'other@example.test', 'message_id' => '<b@example.test>']))->ticket;

    // The reply names A as the direct parent, but also references the newer B.
    $reply = Ticketing::receiveEmail(inbound([
        'message_id' => '<c@example.test>',
        'in_reply_to' => '<a@example.test>',
        'references' => ['<b@example.test>'],
        'text' => 'Threaded to A, please.',
    ]));

    expect($reply->ticket->getKey())->toBe($a->getKey())
        ->and($reply->ticket->getKey())->not->toBe($b->getKey());
});

it('ignores a duplicate delivery of the same Message-ID', function (): void {
    enableInbound();

    // Two DISTINCT DTOs that carry the same Message-ID (a real re-delivery).
    $first = Ticketing::receiveEmail(inbound(['message_id' => '<dupe@example.test>']));
    $second = Ticketing::receiveEmail(inbound(['message_id' => '<dupe@example.test>']));

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($first->ticket->fresh()->messages()->count())->toBe(1);
});

it('does not let a stranger inject into a thread via a replayed Message-ID', function (): void {
    enableInbound();

    // The customer opens a ticket (their address is now on it).
    $ticket = Ticketing::receiveEmail(inbound(['message_id' => '<orig@example.test>']))->ticket;

    // An attacker who knows the (non-secret) Message-ID replays it as In-Reply-To
    // from a different address. It must NOT land on the existing ticket.
    $injected = Ticketing::receiveEmail(inbound([
        'from' => 'attacker@evil.test',
        'message_id' => '<evil@evil.test>',
        'in_reply_to' => '<orig@example.test>',
        'text' => 'malicious',
    ]));

    expect($injected->ticket->getKey())->not->toBe($ticket->getKey())
        ->and($ticket->fresh()->messages()->count())->toBe(1);
});

it('rate limits a flooding sender (fail closed)', function (): void {
    enableInbound();
    config()->set('ticketing.mail.inbound.rate_limit.max_per_minute', 1);

    $first = Ticketing::receiveEmail(inbound());
    $second = Ticketing::receiveEmail(inbound()); // same sender, distinct message id

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull();
});

it('deduplicates before rate limiting so a redelivery does not burn a slot', function (): void {
    enableInbound();
    config()->set('ticketing.mail.inbound.rate_limit.max_per_minute', 2);

    // A consumes slot 1.
    $a = Ticketing::receiveEmail(inbound(['message_id' => '<a@example.test>']));
    // A redelivered: dropped by the dedupe claim BEFORE the rate limiter, so no
    // slot is consumed.
    $dup = Ticketing::receiveEmail(inbound(['message_id' => '<a@example.test>']));
    // B is a genuinely new message; it must still get slot 2.
    $b = Ticketing::receiveEmail(inbound(['message_id' => '<b@example.test>']));

    expect($a)->not->toBeNull()
        ->and($dup)->toBeNull()
        ->and($b)->not->toBeNull();
});

it('releases the idempotency claim when the email is dropped, so a retry reprocesses', function (): void {
    enableInbound();
    config()->set('ticketing.mail.inbound.rate_limit.max_per_minute', 1);

    // First message consumes the only slot.
    Ticketing::receiveEmail(inbound(['message_id' => '<x@example.test>']));

    // A NEW message is rate limited and dropped — its claim must be released.
    $dropped = Ticketing::receiveEmail(inbound(['message_id' => '<y@example.test>']));
    expect($dropped)->toBeNull();

    // Lift the limit; the same Message-ID re-delivered must now ingest (the claim
    // wasn't left dangling on the earlier drop).
    config()->set('ticketing.mail.inbound.rate_limit.max_per_minute', 100);
    $retry = Ticketing::receiveEmail(inbound(['message_id' => '<y@example.test>']));
    expect($retry)->not->toBeNull();
});

it('still sends a notification when no token secret is available for the Reply-To', function (): void {
    config()->set('ticketing.mail.outbound.reply_to_base', 'support@example.test');
    config()->set('ticketing.mail.token.secret', null);
    config()->set('app.key', '');

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $message = Ticketing::for($ticket)->postMessage(makeUser(), 'hi');

    // Must not throw despite the missing secret; the Reply-To is simply omitted.
    $mail = (new ReplyPostedNotification($ticket, $message))->toMail(makeUser());

    expect($mail->replyTo)->toBe([]);
});

it('imports inbound attachments onto the message', function (): void {
    Storage::fake('local');
    enableInbound();

    $message = Ticketing::receiveEmail(inbound([
        'attachments' => [
            ['filename' => 'report.pdf', 'mime' => 'application/pdf', 'content' => '%PDF-1.4 fake'],
        ],
    ]));

    $attachments = TicketAttachment::query()
        ->where('attachable_type', $message->getMorphClass())
        ->where('attachable_id', $message->getKey())
        ->get();

    expect($attachments)->toHaveCount(1)
        ->and($attachments->first()->name)->toBe('report.pdf');
});

it('opens the ticket in the routed tenant', function (): void {
    enableInbound(tenant: 7);

    $message = Ticketing::receiveEmail(inbound());

    // Assert on the TICKET itself (bypassing the tenant scope), so a regression
    // that persisted the tenant on the message but not the ticket is caught.
    $ticket = Ticket::query()->withoutGlobalScopes()->findOrFail($message->ticket_id);

    expect((string) $ticket->getAttribute($ticket->getTenantColumn()))->toBe('7');
});

it('tags the notification Reply-To with the thread address', function (): void {
    config()->set('ticketing.mail.outbound.reply_to_base', 'support@example.test');
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $message = Ticketing::for($ticket)->postMessage(makeUser(), 'hi');

    $mail = (new ReplyPostedNotification($ticket, $message))->toMail(makeUser());

    expect($mail->replyTo)->toHaveCount(1)
        ->and($mail->replyTo[0][0])->toStartWith('support+t_')
        ->and(MailThreadToken::fromRecipients([$mail->replyTo[0][0]]))->toBe((string) $ticket->getKey());
});
