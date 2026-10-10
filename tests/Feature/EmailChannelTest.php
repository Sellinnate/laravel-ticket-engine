<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;
use Selli\Ticketing\Actions\PostMessage;
use Selli\Ticketing\Data\PostMessageData;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Mail\MailMessageIds;
use Selli\Ticketing\Models\Contact;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Notifications\ReplyPostedNotification;
use Selli\Ticketing\Tenancy\TenantContext;
use Symfony\Component\Mime\Email;

/**
 * @param  array<string, mixed>  $overrides
 */
function incoming(array $overrides = []): InboundEmail
{
    return InboundEmail::fromArray(array_merge([
        'from' => 'jane@example.test',
        'recipients' => ['help@example.test'],
        'subject' => 'Printer',
        'text' => 'My printer is broken.',
        'message_id' => '<m-'.bin2hex(random_bytes(6)).'@client.test>',
    ], $overrides));
}

/**
 * The Symfony email a notification's mail would be sent as (callbacks run).
 */
function sentAs(MailMessage $mail): Email
{
    $email = (new Email)->from('help@example.test')->to('jane@example.test')->text('x');

    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }

    return $email;
}

beforeEach(function (): void {
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => 'help@example.test', 'type' => 'support']]);
    config()->set('ticketing.mail.outbound.reply_to_base', 'help@example.test');
});

it('threads a reply through the indexed Message-ID of the previous email', function (): void {
    $first = Ticketing::receiveEmail(incoming(['message_id' => '<first@client.test>']));

    expect($first?->email_message_id)->toBe('first@client.test');

    $reply = Ticketing::receiveEmail(incoming([
        'subject' => 'Re: Printer',
        'text' => 'Still broken.',
        'in_reply_to' => '<first@client.test>',
    ]));

    expect($reply?->ticket_id)->toBe($first->ticket_id)
        ->and(Ticket::query()->count())->toBe(1);
});

it('threads a reply to our own notification through its signed Message-ID', function (): void {
    $first = Ticketing::receiveEmail(incoming());
    $ticket = Ticket::query()->findOrFail($first?->ticket_id);
    $answer = Ticketing::postMessage($ticket, makeUser(), 'Try turning it off and on.');

    $sent = sentAs((new ReplyPostedNotification($ticket, $answer))->toMail(makeUser()));
    $ourId = $sent->getHeaders()->get('Message-ID')?->getBodyAsString();

    expect(MailMessageIds::ticketFrom((string) $ourId))->toBe((string) $ticket->getKey());

    // The customer's client answers to our email (no reply-address token).
    $reply = Ticketing::receiveEmail(incoming([
        'recipients' => ['help@example.test'],
        'text' => 'Done, thanks.',
        'in_reply_to' => $ourId,
    ]));

    expect($reply?->ticket_id)->toBe($ticket->getKey());

    // A forged id of ours does not thread.
    $forged = Ticketing::receiveEmail(incoming(['in_reply_to' => '<tk.abc.'.$ticket->getKey().'.forgedsignature@example.test>']));
    expect($forged?->ticket_id)->not->toBe($ticket->getKey());
});

it('threads on the subject reference only for a sender already on the ticket', function (): void {
    $first = Ticketing::receiveEmail(incoming());
    $ticket = Ticket::query()->findOrFail($first?->ticket_id);

    $reply = Ticketing::receiveEmail(incoming(['subject' => 'Re: Printer ['.$ticket->reference.']', 'text' => 'Any news?']));
    $stranger = Ticketing::receiveEmail(incoming(['from' => 'mallory@evil.test', 'subject' => 'Re: ['.$ticket->reference.']', 'text' => 'Inject']));

    expect($reply?->ticket_id)->toBe($ticket->getKey())
        ->and($stranger?->ticket_id)->not->toBe($ticket->getKey());
});

it('treats a reference shared by too many tickets as ambiguous', function (): void {
    $context = app(TenantContext::class);
    $tickets = [];

    foreach (range(1, 6) as $tenant) {
        $tickets[$tenant] = $context->forTenant($tenant, function (): Ticket {
            $ticket = Ticketing::open(type: 'support', title: 'Same reference');
            $ticket->forceFill(['reference' => 'SUP-1'])->save();

            return $ticket;
        });
    }

    // The sender is on the first and the sixth: no unique match.
    foreach ([1, 6] as $tenant) {
        $context->forTenant($tenant, fn () => Ticketing::postMessage($tickets[$tenant], null, 'Hello', meta: ['from' => 'jane@example.test']));
    }

    $reply = Ticketing::receiveEmail(incoming(['subject' => 'Re: [SUP-1]', 'text' => 'Which one?']));

    expect(collect($tickets)->map->getKey()->all())->not->toContain($reply?->ticket_id);
});

it('accepts header threading from a contact on the ticket who never emailed', function (): void {
    // A widget visitor's ticket, answered by mail later on.
    $contact = Ticketing::contacts()->remember('visitor@example.test', 'Visitor');
    $ticket = Ticketing::open(type: 'support', title: 'From the widget', requester: $contact);
    $answer = Ticketing::postMessage($ticket, makeUser(), 'Hello!');
    $ourId = sentAs((new ReplyPostedNotification($ticket, $answer))->toMail($contact))->getHeaders()->get('Message-ID')?->getBodyAsString();

    $reply = Ticketing::receiveEmail(incoming(['from' => 'Visitor@Example.test', 'in_reply_to' => $ourId]));

    expect($reply?->ticket_id)->toBe($ticket->getKey());
});

it('drops bounces, no-reply senders and our own mail', function (array $email): void {
    expect(Ticketing::receiveEmail(incoming($email)))->toBeNull()
        ->and(Ticket::query()->count())->toBe(0);
})->with([
    'delivery report' => [['headers' => ['Content-Type' => 'multipart/report; report-type=delivery-status']]],
    'failed recipients' => [['headers' => ['X-Failed-Recipients' => 'jane@example.test']]],
    'null return path' => [['headers' => ['Return-Path' => '<>']]],
    'mailer daemon' => [['from' => 'MAILER-DAEMON@mx.example.test']],
    'postmaster' => [['from' => 'postmaster@example.test']],
    'no-reply' => [['from' => 'no-reply@shop.test']],
    'noreply' => [['from' => 'noreply@shop.test']],
    'do-not-reply' => [['from' => 'do-not-reply@shop.test']],
    'our header' => [['headers' => ['X-Ticketing-Mailer' => 'selli/ticketing']]],
]);

it('drops a notification of ours delivered back to the inbox', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Loop');

    expect(Ticketing::receiveEmail(incoming(['message_id' => '<'.MailMessageIds::forTicket($ticket->getKey()).'>'])))->toBeNull()
        ->and(Ticket::query()->count())->toBe(1);
});

it('stores what the sender wrote and keeps the original in meta', function (): void {
    $text = "Still broken.\n\nOn Mon, 5 Oct 2026 at 10:00, Help <help@example.test> wrote:\n> Try restarting it.";

    $message = Ticketing::receiveEmail(incoming(['text' => $text]));

    expect($message?->body)->toBe('Still broken.')
        ->and($message?->meta['original_body'])->toBe($text);

    config()->set('ticketing.mail.inbound.strip_quotes', false);
    $whole = Ticketing::receiveEmail(incoming(['text' => $text]));

    expect($whole?->body)->toBe($text)
        ->and($whole?->meta)->not->toHaveKey('original_body');
});

it('strips the quoted part of HTML-only mail', function (): void {
    $message = Ticketing::receiveEmail(incoming(['text' => null, 'html' => '<p>Fixed!</p><div class="gmail_quote">old</div>']));

    expect($message?->body)->toBe('<p>Fixed!</p>');
});

it('adds Cc addresses as cc participants, never our own or the sender', function (): void {
    $message = Ticketing::receiveEmail(incoming([
        'recipients' => ['help@example.test', 'boss@example.test'],
        'cc' => ['Boss@Example.test', 'help@example.test', 'jane@example.test', 'help+t_x.y@example.test', 'not an address'],
    ]));

    $ticket = Ticket::query()->findOrFail($message?->ticket_id);
    $cc = $ticket->participants()->where('role', ParticipantRole::Cc->value)->get();

    expect($cc)->toHaveCount(1)
        ->and(Contact::query()->findOrFail($cc->first()->participant_id)->email)->toBe('boss@example.test');

    // The Cc'd colleague can now reply into the thread.
    $reply = Ticketing::receiveEmail(incoming(['from' => 'boss@example.test', 'in_reply_to' => $message->getAttribute('email_message_id')]));
    expect($reply?->ticket_id)->toBe($ticket->getKey());

    config()->set('ticketing.mail.inbound.cc_participants', false);
    $other = Ticketing::receiveEmail(incoming(['cc' => ['someone@example.test']]));
    expect(Ticket::query()->findOrFail($other?->ticket_id)->participants()->where('role', ParticipantRole::Cc->value)->count())->toBe(0);
});

it('caps the Cc participants of one email', function (): void {
    $cc = array_map(fn (int $i): string => "cc{$i}@example.test", range(1, 15));

    $message = Ticketing::receiveEmail(incoming(['cc' => $cc]));

    expect(Ticket::query()->findOrFail($message?->ticket_id)->participants()->where('role', ParticipantRole::Cc->value)->count())->toBe(10);
});

it('threads outbound mail on the customer\'s email and marks it as ours', function (): void {
    $first = Ticketing::receiveEmail(incoming(['message_id' => '<origin@client.test>']));
    $ticket = Ticket::query()->findOrFail($first?->ticket_id);
    $answer = Ticketing::postMessage($ticket, makeUser(), "First line.\n\nSee [the guide](https://evil.test).");

    $mail = (new ReplyPostedNotification($ticket, $answer))->toMail(makeUser());
    $headers = sentAs($mail)->getHeaders();

    expect($mail->subject)->toBe('Re: Printer ['.$ticket->reference.']')
        ->and($mail->introLines)->toContain('First line.')
        ->and(implode("\n", $mail->introLines))->not->toContain('](')
        ->and($headers->get('In-Reply-To')?->getBodyAsString())->toBe('<origin@client.test>')
        ->and($headers->get('References')?->getBodyAsString())->toBe('<origin@client.test>')
        ->and($headers->get('X-Ticketing-Mailer')?->getBodyAsString())->toBe('selli/ticketing')
        ->and($headers->has('Auto-Submitted'))->toBeFalse();
});

it('threads mail about a ticket without email on its thread root, and flags AI replies as automatic', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'Re: From the app');
    $ai = app(PostMessage::class)->handle(new PostMessageData(ticket: $ticket, author: null, body: 'Here is how.', source: MessageSource::Ai));

    $mail = (new ReplyPostedNotification($ticket, $ai))->toMail(makeUser());
    $headers = sentAs($mail)->getHeaders();

    expect($mail->subject)->toBe('Re: From the app ['.$ticket->reference.']')
        ->and($headers->get('In-Reply-To')?->getBodyAsString())->toBe('<'.MailMessageIds::root($ticket->getKey()).'>')
        ->and($headers->get('Auto-Submitted')?->getBodyAsString())->toBe('auto-replied');
});

it('never puts an invalid stored Message-ID in a header', function (): void {
    $first = Ticketing::receiveEmail(incoming(['message_id' => '<not a valid id>']));
    $ticket = Ticket::query()->findOrFail($first?->ticket_id);
    $answer = Ticketing::postMessage($ticket, makeUser(), 'Ok');

    $headers = sentAs((new ReplyPostedNotification($ticket, $answer))->toMail(makeUser()))->getHeaders();

    expect($headers->get('In-Reply-To')?->getBodyAsString())->toBe('<'.MailMessageIds::root($ticket->getKey()).'>');
});

it('hashes a Message-ID longer than its column', function (): void {
    $long = '<'.str_repeat('a', 250).'@client.test>';

    $message = Ticketing::receiveEmail(incoming(['message_id' => $long]));
    $reply = Ticketing::receiveEmail(incoming(['in_reply_to' => $long, 'text' => 'Again']));

    expect($message?->email_message_id)->toStartWith('sha256:')
        ->and($reply?->ticket_id)->toBe($message?->ticket_id)
        // A redelivery is still recognised.
        ->and(Ticketing::receiveEmail(incoming(['message_id' => $long])))->toBeNull();
});

it('stores the email message id on the message model', function (): void {
    $message = Ticketing::receiveEmail(incoming(['message_id' => '<stored@client.test>']));

    expect(TicketMessage::query()->where('email_message_id', 'stored@client.test')->value('id'))->toBe($message?->getKey());
});
