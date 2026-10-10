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
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Selli\Ticketing\Contracts\InboundMailbox;
use Selli\Ticketing\Exceptions\InvalidConfigurationException;
use Throwable;

/**
 * An IMAP mailbox on directorytree/imapengine (an optional dependency): reads
 * the unseen messages of a folder, oldest first, and once each is ingested
 * moves it to `processed_folder` or, without one, flags it seen.
 *
 * A message that fails is retried with a growing delay (1, 2, 4… minutes, at
 * most 6 hours) while newer mail goes on being read, and after
 * `max_attempts` failures it is set aside — moved to `failed_folder`, or
 * flagged and marked seen — so a person can look at it. A message the handler
 * declines for now (a rate-limited sender) waits a minute.
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
        protected ?string $failedFolder = null,
        protected int $maxAttempts = 10,
        protected ?Repository $cache = null,
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
        $folder = fn (string $key): ?string => is_string($config[$key] ?? null) && $config[$key] !== '' ? $config[$key] : null;

        return new self(
            new Mailbox(array_filter($connection, fn (mixed $value): bool => $value !== null)),
            $folder('folder') ?? 'INBOX',
            $folder('processed_folder'),
            $folder('failed_folder'),
            is_numeric($config['max_attempts'] ?? null) ? max(1, (int) $config['max_attempts']) : 10,
        );
    }

    public function poll(Closure $handler, int $limit = 50): int
    {
        $limit = max(1, $limit);
        $folder = strtoupper($this->folder) === 'INBOX' ? $this->mailbox->inbox() : $this->mailbox->folders()->findOrFail($this->folder);
        $now = now()->getTimestamp();
        $state = $this->state();
        $waiting = count(array_filter($state, fn (array $entry): bool => $entry['retry_at'] > $now));

        $query = $folder->messages();

        if ($query instanceof MessageQuery) {
            $query->unseen(); // IMAP SEARCH UNSEEN, on the server
        }

        // Read past the messages still waiting for a retry, so they never
        // keep newer mail out of the batch.
        $messages = $query->withHeaders()->withBody()->withFlags()->leaveUnread()->oldest()
            ->limit(min($limit + $waiting, $limit * 10))
            ->get();

        $handled = 0;
        $processed = 0;

        foreach ($messages as $message) {
            // The server already filtered on UNSEEN; this guards a mailbox
            // whose query cannot (a fake, a custom folder implementation).
            if ($handled >= $limit || $message->isSeen()) {
                continue;
            }

            // Prefixed: a numeric string would become an integer array key.
            $uid = 'uid:'.$message->uid();

            if (($state[$uid]['retry_at'] ?? 0) > $now) {
                continue;
            }

            $handled++;

            try {
                $accepted = $handler(self::toInboundEmail($message));
            } catch (Throwable $exception) {
                report($exception);

                $attempts = ($state[$uid]['attempts'] ?? 0) + 1;

                if ($attempts >= $this->maxAttempts) {
                    $this->setAside($message);
                    unset($state[$uid]);
                } else {
                    $state[$uid] = ['attempts' => $attempts, 'retry_at' => $now + (int) min(60 * 2 ** ($attempts - 1), 21600)];
                }

                continue;
            }

            // "Not now" (a rate-limited sender): tried again in a minute.
            if ($accepted === false) {
                $state[$uid] = ['attempts' => $state[$uid]['attempts'] ?? 0, 'retry_at' => $now + 60];

                continue;
            }

            unset($state[$uid]);

            if ($this->processedFolder !== null && $message instanceof Message) {
                $message->move($this->processedFolder);
            } else {
                $message->markSeen();
            }

            $processed++;
        }

        $this->saveState($state);

        return $processed;
    }

    /**
     * A message that kept failing: out of the way, but kept for a person.
     */
    protected function setAside(MessageInterface $message): void
    {
        if ($this->failedFolder !== null && $message instanceof Message) {
            $message->move($this->failedFolder);

            return;
        }

        $message->markFlagged();
        $message->markSeen();
    }

    /**
     * Retry bookkeeping per message UID, in the cache.
     *
     * @return array<string, array{attempts: int, retry_at: int}>
     */
    protected function state(): array
    {
        $state = $this->store()->get($this->stateKey());

        if (! is_array($state)) {
            return [];
        }

        $valid = [];
        foreach ($state as $uid => $entry) {
            if (is_string($uid) && is_array($entry) && is_int($entry['attempts'] ?? null) && is_int($entry['retry_at'] ?? null)) {
                $valid[$uid] = ['attempts' => $entry['attempts'], 'retry_at' => $entry['retry_at']];
            }
        }

        return $valid;
    }

    /**
     * @param  array<string, array{attempts: int, retry_at: int}>  $state
     */
    protected function saveState(array $state): void
    {
        $state === []
            ? $this->store()->forget($this->stateKey())
            : $this->store()->put($this->stateKey(), $state, now()->addDays(7));
    }

    protected function stateKey(): string
    {
        $host = $this->mailbox->config('host');
        $user = $this->mailbox->config('username');

        return 'ticketing:imap:'.hash('sha256', (is_scalar($host) ? (string) $host : '').'|'.(is_scalar($user) ? (string) $user : '').'|'.$this->folder);
    }

    protected function store(): Repository
    {
        return $this->cache ?? Cache::store();
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
