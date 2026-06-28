<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Selli\Ticketing\Enums\BodyFormat;

/**
 * A provider-agnostic, normalised inbound email.
 *
 * The package never parses a provider webhook itself: a host (or the optional
 * beyondcode/laravel-mailbox bridge) builds one of these — via the constructor
 * or {@see fromArray} — and hands it to Ticketing::receiveEmail(). Keeping the
 * normalised shape here means the threading/anti-loop/routing logic is tested
 * once, independent of any mail provider.
 */
final readonly class InboundEmail
{
    /**
     * @param  list<string>  $recipients  every To/Cc address (used for routing + the +t_ token)
     * @param  list<string>  $references  Message-IDs from the References header
     * @param  array<string, string>  $headers  lower-cased header name => value (for anti-loop checks)
     * @param  list<array{filename: string, mime: string, content: string}>  $attachments
     */
    public function __construct(
        public string $from,
        public array $recipients = [],
        public string $subject = '',
        public ?string $text = null,
        public ?string $html = null,
        public ?string $messageId = null,
        public ?string $inReplyTo = null,
        public array $references = [],
        public array $headers = [],
        public array $attachments = [],
        public ?string $fromName = null,
    ) {}

    /**
     * Build from a loosely-typed array (e.g. a provider webhook the host mapped).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $headers = [];
        if (is_array($data['headers'] ?? null)) {
            foreach ($data['headers'] as $name => $value) {
                if (is_string($name) && (is_string($value) || is_numeric($value))) {
                    $headers[strtolower($name)] = (string) $value;
                }
            }
        }

        return new self(
            from: (string) ($data['from'] ?? ''),
            recipients: self::stringList($data['recipients'] ?? $data['to'] ?? []),
            subject: (string) ($data['subject'] ?? ''),
            text: isset($data['text']) ? (string) $data['text'] : null,
            html: isset($data['html']) ? (string) $data['html'] : null,
            messageId: isset($data['message_id']) ? (string) $data['message_id'] : null,
            inReplyTo: isset($data['in_reply_to']) ? (string) $data['in_reply_to'] : null,
            references: self::stringList($data['references'] ?? []),
            headers: $headers,
            attachments: self::normaliseAttachments($data['attachments'] ?? []),
            fromName: isset($data['from_name']) ? (string) $data['from_name'] : null,
        );
    }

    /**
     * The body to store, preferring the plain-text part; HTML is the fallback.
     */
    public function body(): string
    {
        if ($this->text !== null && trim($this->text) !== '') {
            return $this->text;
        }

        return $this->html ?? '';
    }

    public function bodyFormat(): BodyFormat
    {
        return ($this->text === null || trim($this->text) === '') && $this->html !== null
            ? BodyFormat::Html
            : BodyFormat::Text;
    }

    /**
     * Is this an automated message (auto-reply, vacation responder, bulk/list
     * mail)? Such messages must never open a ticket or trigger a reply, or two
     * autoresponders ping-pong forever. Recognises the standard signals.
     */
    public function isAutoReply(): bool
    {
        $autoSubmitted = strtolower($this->headers['auto-submitted'] ?? '');
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        $precedence = strtolower($this->headers['precedence'] ?? '');
        if (in_array($precedence, ['bulk', 'auto_reply', 'list', 'junk'], true)) {
            return true;
        }

        foreach (['x-auto-response-suppress', 'x-autoreply', 'x-autorespond', 'list-id', 'list-unsubscribe'] as $header) {
            if (isset($this->headers[$header]) && trim($this->headers[$header]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<array{filename: string, mime: string, content: string}>
     */
    private static function normaliseAttachments(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_array($item) && isset($item['filename'], $item['content'])) {
                $out[] = [
                    'filename' => (string) $item['filename'],
                    'mime' => (string) ($item['mime'] ?? 'application/octet-stream'),
                    'content' => (string) $item['content'],
                ];
            }
        }

        return $out;
    }
}
