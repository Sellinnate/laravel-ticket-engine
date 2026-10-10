<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Closure;
use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\Attachment;
use DirectoryTree\ImapEngine\Connection\RawQueryValue;
use DirectoryTree\ImapEngine\Enums\ImapSearchKey;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Mailbox;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\Message;
use DirectoryTree\ImapEngine\MessageInterface;
use DirectoryTree\ImapEngine\MessageQuery;
use DirectoryTree\ImapEngine\MessageQueryInterface;
use DirectoryTree\ImapEngine\Support\Str;
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

    /** The UIDVALIDITY of the folder being polled. */
    protected ?string $uidValidity = null;

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
        $this->uidValidity = $this->uidValidity($folder);
        $state = $this->state();

        // The messages still waiting for a retry are left out of the search, so
        // however many there are they never keep newer mail out of the batch.
        $waiting = [];
        foreach ($state as $key => $entry) {
            if ($entry['retry_at'] > $now) {
                $waiting[] = (int) substr($key, 4);
            }
        }

        $query = $this->search($folder->messages(), $waiting);

        $messages = $query->withHeaders()->withBody()->withFlags()->leaveUnread()->oldest()->limit($limit)->get();

        $handled = 0;
        $processed = 0;

        // Kept even when acknowledging a message fails (a missing folder, a
        // dropped connection): the retry counts of this run are not lost.
        try {
            foreach ($messages as $message) {
                // The server already filtered on UNSEEN and the waiting UIDs; this
                // guards a mailbox whose query cannot (a fake, a custom folder).
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
        } finally {
            $this->saveState($state);
        }

        return $processed;
    }

    /**
     * The unseen messages, without those waiting for a retry — narrowed on the
     * server when the query speaks IMAP.
     *
     * @param  list<int>  $waiting  UIDs
     */
    protected function search(MessageQueryInterface $query, array $waiting): MessageQueryInterface
    {
        if (! $query instanceof MessageQuery) {
            return $query;
        }

        $query->unseen();

        if ($waiting !== []) {
            sort($waiting);
            $query->whereNot(ImapSearchKey::Uid, new RawQueryValue(Str::set(array_slice($waiting, 0, 5000))));
        }

        return $query;
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

        // UIDs are only meaningful within one UIDVALIDITY: a recreated folder
        // starts a fresh bookkeeping.
        return 'ticketing:imap:'.hash('sha256', implode('|', [
            is_scalar($host) ? (string) $host : '',
            is_scalar($user) ? (string) $user : '',
            $this->folder,
            $this->uidValidity ?? '',
        ]));
    }

    protected function uidValidity(FolderInterface $folder): ?string
    {
        try {
            $status = $folder->status();
        } catch (Throwable) {
            return null;
        }

        $validity = $status['UIDVALIDITY'] ?? $status['uidvalidity'] ?? null;

        return is_scalar($validity) ? (string) $validity : null;
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
