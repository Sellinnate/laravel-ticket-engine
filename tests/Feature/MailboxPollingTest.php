<?php

declare(strict_types=1);

use DirectoryTree\ImapEngine\Connection\ImapQueryBuilder;
use DirectoryTree\ImapEngine\MessageQuery;
use DirectoryTree\ImapEngine\Testing\FakeFolder;
use DirectoryTree\ImapEngine\Testing\FakeMailbox;
use DirectoryTree\ImapEngine\Testing\FakeMessage;
use Illuminate\Contracts\Container\Container;
use Selli\Ticketing\Contracts\InboundMailbox;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Mail\ImapMailbox;
use Selli\Ticketing\Mail\InboundEmail;
use Selli\Ticketing\Models\Ticket;

function rawEmail(string $messageId, string $from = 'Jane Doe <jane@example.test>', string $extra = ''): string
{
    return "From: {$from}\r\nTo: help@example.test\r\nCc: Boss <boss@example.test>\r\nSubject: Printer\r\n"
        ."Message-ID: <{$messageId}>\r\nIn-Reply-To: <parent@client.test>\r\nReferences: <root@client.test> <parent@client.test>\r\n"
        ."{$extra}Content-Type: text/plain; charset=utf-8\r\n\r\nMy printer is broken.\r\n";
}

/**
 * @param  list<FakeMessage>  $messages
 */
function fakeImap(array $messages): FakeMailbox
{
    return new FakeMailbox(folders: [new FakeFolder('inbox', messages: $messages)]);
}

beforeEach(function (): void {
    config()->set('ticketing.mail.inbound.enabled', true);
    config()->set('ticketing.mail.inbound.routes', [['match' => 'help@example.test', 'type' => 'support']]);
});

it('converts an IMAP message into an inbound email', function (): void {
    $email = ImapMailbox::toInboundEmail(new FakeMessage(1, [], rawEmail('one@client.test', extra: "Auto-Submitted: auto-replied\r\n")));

    expect($email->from)->toBe('jane@example.test')
        ->and($email->fromName)->toBe('Jane Doe')
        ->and($email->recipients)->toBe(['help@example.test', 'boss@example.test'])
        ->and($email->cc)->toBe(['boss@example.test'])
        ->and($email->subject)->toBe('Printer')
        ->and(trim((string) $email->text))->toBe('My printer is broken.')
        ->and($email->messageId)->toContain('one@client.test')
        ->and($email->inReplyTo)->toContain('parent@client.test')
        ->and($email->references)->toHaveCount(2)
        ->and($email->headers['auto-submitted'])->toBe('auto-replied')
        ->and($email->isAutomated())->toBeTrue();
});

it('ingests the unseen messages and flags them seen', function (): void {
    $seen = new FakeMessage(1, ['\\Seen'], rawEmail('old@client.test'));
    $new = new FakeMessage(2, [], rawEmail('new@client.test'));
    $mailbox = new ImapMailbox(fakeImap([$seen, $new]));

    $count = $mailbox->poll(fn (InboundEmail $email) => Ticketing::receiveEmail($email));

    expect($count)->toBe(1)
        ->and($new->isSeen())->toBeTrue()
        ->and(Ticket::query()->count())->toBe(1)
        ->and(Ticket::query()->sole()->messages()->sole()->email_message_id)->toBe('new@client.test');
});

it('leaves a message whose ingestion fails for the next poll', function (): void {
    $broken = new FakeMessage(1, [], rawEmail('broken@client.test'));
    $fine = new FakeMessage(2, [], rawEmail('fine@client.test'));
    $mailbox = new ImapMailbox(fakeImap([$broken, $fine]));

    $count = $mailbox->poll(function (InboundEmail $email): void {
        if (str_contains((string) $email->messageId, 'broken')) {
            throw new RuntimeException('provider hiccup');
        }
    });

    expect($count)->toBe(1)
        ->and($broken->isSeen())->toBeFalse()
        ->and($fine->isSeen())->toBeTrue();
});

it('never lets a failing message keep newer mail out', function (): void {
    $poison = new FakeMessage(1, [], rawEmail('poison@client.test'));
    $fine = new FakeMessage(2, [], rawEmail('fine@client.test'));
    $mailbox = new ImapMailbox(fakeImap([$poison, $fine]));
    $handler = function (InboundEmail $email): void {
        if (str_contains((string) $email->messageId, 'poison')) {
            throw new RuntimeException('cannot parse');
        }
    };

    expect($mailbox->poll($handler, limit: 1))->toBe(0)   // the poison message used the run
        ->and($mailbox->poll($handler, limit: 1))->toBe(1) // it waits; the next one goes through
        ->and($fine->isSeen())->toBeTrue()
        ->and($poison->isSeen())->toBeFalse();
});

it('leaves the messages waiting for a retry out of the IMAP search', function (): void {
    $mailbox = new class(new FakeMailbox) extends ImapMailbox
    {
        public function searchFor(array $waiting): string
        {
            return $this->search(new MessageQuery(new FakeFolder('inbox'), new ImapQueryBuilder), $waiting)->toImap();
        }
    };

    expect($mailbox->searchFor([]))->toBe('UNSEEN')
        ->and($mailbox->searchFor([9, 3, 4, 5]))->toBe('UNSEEN NOT UID 3:5,9');
});

it('keeps the retry bookkeeping when acknowledging a message fails', function (): void {
    $poison = new FakeMessage(1, [], rawEmail('poison@client.test'));
    $sticky = new class(2, [], rawEmail('sticky@client.test')) extends FakeMessage
    {
        public function markSeen(): void
        {
            throw new RuntimeException('connection dropped');
        }
    };
    $mailbox = new ImapMailbox(fakeImap([$poison, $sticky]));
    $calls = [];
    $handler = function (InboundEmail $email) use (&$calls): void {
        $calls[] = (string) $email->messageId;

        if (str_contains((string) $email->messageId, 'poison')) {
            throw new RuntimeException('cannot parse');
        }
    };

    expect(fn () => $mailbox->poll($handler))->toThrow(RuntimeException::class, 'connection dropped');

    // The poison message's failure was recorded: it waits, it is not retried at once.
    $calls = [];
    expect(fn () => $mailbox->poll($handler))->toThrow(RuntimeException::class, 'connection dropped');
    expect($calls)->toHaveCount(1)->and($calls[0])->toContain('sticky');
});

it('sets a message aside after repeated failures, with a growing delay in between', function (): void {
    $poison = new FakeMessage(1, [], rawEmail('poison@client.test'));
    $mailbox = new ImapMailbox(fakeImap([$poison]), maxAttempts: 3);
    $calls = 0;
    $handler = function () use (&$calls): void {
        $calls++;

        throw new RuntimeException('cannot parse');
    };

    $mailbox->poll($handler);
    $mailbox->poll($handler);                // still waiting (1 minute)
    $this->travel(61)->seconds();
    $mailbox->poll($handler);                // second failure: waits 2 minutes
    $this->travel(60)->seconds();
    $mailbox->poll($handler);                // still waiting
    $this->travel(61)->seconds();
    $mailbox->poll($handler);                // third failure: set aside

    expect($calls)->toBe(3)
        ->and($poison->isSeen())->toBeTrue()
        ->and($poison->isFlagged())->toBeTrue();
});

it('polls the configured mailboxes from the command', function (): void {
    $message = new FakeMessage(1, [], rawEmail('cmd@client.test'));
    config()->set('ticketing.mail.inbound.mailboxes', ['support' => ['driver' => 'fake-imap']]);
    Ticketing::mailboxes()->extend('fake-imap', fn (Container $app, array $config): InboundMailbox => new ImapMailbox(fakeImap([$message])));

    $this->artisan('ticketing:mail:poll')->expectsOutputToContain('support — 1 message(s)')->assertSuccessful();
    $this->artisan('ticketing:mail:poll', ['mailbox' => ['support']])->expectsOutputToContain('support — 0 message(s)')->assertSuccessful();

    expect(Ticket::query()->count())->toBe(1);
});

it('leaves a rate-limited sender\'s mail in the mailbox for a later run', function (): void {
    config()->set('ticketing.mail.inbound.rate_limit.max_per_minute', 1);
    $first = new FakeMessage(1, [], rawEmail('burst-1@client.test'));
    $second = new FakeMessage(2, [], rawEmail('burst-2@client.test'));
    $automated = new FakeMessage(3, [], rawEmail('auto@client.test', extra: "Auto-Submitted: auto-replied\r\n"));
    config()->set('ticketing.mail.inbound.mailboxes', ['support' => ['driver' => 'fake-imap']]);
    Ticketing::mailboxes()->extend('fake-imap', fn (): InboundMailbox => new ImapMailbox(fakeImap([$first, $second, $automated])));

    $this->artisan('ticketing:mail:poll')->expectsOutputToContain('support — 2 message(s)')->assertSuccessful();

    expect($first->isSeen())->toBeTrue()
        ->and($second->isSeen())->toBeFalse()   // retried by a later run
        ->and($automated->isSeen())->toBeTrue() // dropped for good
        ->and(Ticket::query()->count())->toBe(1);
});

it('refuses to poll while inbound email is disabled, and reports unknown mailboxes', function (): void {
    config()->set('ticketing.mail.inbound.enabled', false);
    $this->artisan('ticketing:mail:poll')->assertFailed();

    config()->set('ticketing.mail.inbound.enabled', true);
    $this->artisan('ticketing:mail:poll')->expectsOutputToContain('No inbound mailboxes configured.')->assertSuccessful();
    $this->artisan('ticketing:mail:poll', ['mailbox' => ['nope']])->expectsOutputToContain('Unknown inbound mailbox [nope].')->assertFailed();

    config()->set('ticketing.mail.inbound.mailboxes', ['x' => ['driver' => 'pop3']]);
    expect(fn () => Ticketing::mailboxes()->mailbox('x'))->toThrow(InvalidConfigurationException::class, 'Unknown inbound mailbox driver [pop3].');
});

it('keeps polling the other mailboxes when one server fails', function (): void {
    $message = new FakeMessage(1, [], rawEmail('ok@client.test'));
    config()->set('ticketing.mail.inbound.mailboxes', ['down' => ['driver' => 'broken'], 'up' => ['driver' => 'fake-imap']]);
    Ticketing::mailboxes()
        ->extend('broken', fn (): InboundMailbox => new class implements InboundMailbox
        {
            public function poll(Closure $handler, int $limit = 50): int
            {
                throw new RuntimeException('Connection refused');
            }
        })
        ->extend('fake-imap', fn (): InboundMailbox => new ImapMailbox(fakeImap([$message])));

    $this->artisan('ticketing:mail:poll')
        ->expectsOutputToContain('down — Connection refused')
        ->expectsOutputToContain('up — 1 message(s)')
        ->assertFailed();
});

it('builds an IMAP mailbox from its configuration', function (): void {
    config()->set('ticketing.mail.inbound.mailboxes', ['support' => [
        'host' => 'imap.example.test', 'username' => 'u', 'password' => 'p', 'processed_folder' => 'Done',
    ]]);

    expect(Ticketing::mailboxes()->mailbox('support'))->toBeInstanceOf(ImapMailbox::class)
        ->and(Ticketing::mailboxes()->names())->toBe(['support']);
});
