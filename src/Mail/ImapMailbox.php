<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Closure;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQuery;
use Selli\Ticketing\Contracts\InboundMailbox;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Throwable;

/**
 * An IMAP mailbox on directorytree/imapengine (an optional dependency): reads
 * the unseen messages of a folder, oldest first, and once each is ingested
 * moves it to `processed_folder` or, without one, flags it seen.
 */
class ImapMailbox implements InboundMailbox
{
    /**
     * The headers the anti-loop checks read.
     *
     * @var list<string>
     */
    protected const HEADERS = [
        'auto-submitted', 'precedence', 'x-auto-response-suppress', 'x-autoreply', 'x-autorespond',
        'list-id', 'list-unsubscribe', 'content-type', 'x-failed-recipients', 'return-path', 'x-ticketing-mailer',
    ];

    public function __construct(
        protected MailboxInterface $mailbox,
        protected string $folder = 'INBOX',
        protected ?string $processedFolder = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        if (! class_exists(Mailbox::class)) {
            throw new InvalidConfigurationException('The imap mailbox driver needs directorytree/imapengine: composer require directorytree/imapengine');
        }

        $connection = array_intersect_key($config, array_flip(['host', 'port', 'username', 'password', 'encryption', 'validate_cert', 'authentication', 'timeout']));

        return new self(
            new Mailbox(array_filter($connection, fn (mixed $value): bool => $value !== null)),
            is_string($config['folder'] ?? null) ? $config['folder'] : 'INBOX',
            is_string($config['processed_folder'] ?? null) && $config['processed_folder'] !== '' ? $config['processed_folder'] : null,
        );
    }

    public function poll(Closure $handler, int $limit = 50): int
    {
        $folder = strtoupper($this->folder) === 'INBOX' ? $this->mailbox->inbox() : $this->mailbox->folders()->findOrFail($this->folder);

        $query = $folder->messages();

        if ($query instanceof MessageQuery) {
            $query->unseen(); // IMAP SEARCH UNSEEN, on the server
        }

        $messages = $query->withHeaders()->withBody()->withFlags()->leaveUnread()->oldest()->limit(max(1, $limit))->get();
        $processed = 0;

        foreach ($messages as $message) {
            // The server already filtered on UNSEEN; this guards a mailbox
            // whose query cannot (a fake, a custom folder implementation).
            if ($message->isSeen()) {
                continue;
            }

            try {
                $handler(self::toInboundEmail($message));
            } catch (Throwable $exception) {
                // Left unseen: the next poll tries it again.
                report($exception);

                continue;
            }

            if ($this->processedFolder !== null && $message instanceof Message) {
                $message->move($this->processedFolder);
            } else {
                $message->markSeen();
            }

            $processed++;
        }

        return $processed;
    }

    public static function toInboundEmail(MessageInterface $message): InboundEmail
    {
        $addresses = fn (array $list): array => array_values(array_filter(array_map(
            fn (Address $address): string => $address->email(),
            $list,
        ), fn (string $email): bool => $email !== ''));

        $headers = [];
        foreach (self::HEADERS as $name) {
            $value = $message->header($name)?->getRawValue();

            if (is_string($value) && trim($value) !== '') {
                $headers[$name] = trim($value);
            }
        }

        $references = [];
        foreach ($message->header('references')?->getParts() ?? [] as $part) {
            $value = $part->getValue();

            if (is_string($value) && $value !== '') {
                $references[] = $value;
            }
        }

        return new InboundEmail(
            from: $message->from()?->email() ?? '',
            recipients: [...$addresses($message->to()), ...$addresses($message->cc())],
            subject: (string) $message->subject(),
            text: $message->text(),
            html: $message->html(),
            messageId: $message->messageId(),
            inReplyTo: $message->inReplyTo()[0] ?? null,
            references: $references,
            headers: $headers,
            attachments: array_values(array_map(fn (Attachment $attachment): array => [
                'filename' => $attachment->filename() ?? 'attachment',
                'mime' => $attachment->contentType(),
                'content' => $attachment->contents(),
            ], $message->attachments())),
            fromName: ($name = $message->from()?->name()) !== null && $name !== '' ? $name : null,
            cc: $addresses($message->cc()),
        );
    }
}
