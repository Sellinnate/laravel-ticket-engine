<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

use Selli\Ticketing\Enums\BodyFormat;

/**
 * A provider-agnostic, normalised inbound email.
 *
 * The package never parses a provider webhook itself: a host (or an inbound
 * mailbox adapter) builds one of these — via the constructor or
 * {@see fromArray} — and hands it to Ticketing::receiveEmail(). Keeping the
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
     * @param  list<string>  $cc  the Cc addresses alone (they become `cc` participants)
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
        public array $cc = [],
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

        $cc = self::stringList($data['cc'] ?? []);

        return new self(
            from: (string) ($data['from'] ?? ''),
            // The Cc'd addresses route and thread too (a support address or a
            // reply token may only be in Cc), as the IMAP adapter does.
            recipients: array_values(array_unique([...self::stringList($data['recipients'] ?? $data['to'] ?? []), ...$cc])),
            subject: (string) ($data['subject'] ?? ''),
            text: isset($data['text']) ? (string) $data['text'] : null,
            html: isset($data['html']) ? (string) $data['html'] : null,
            messageId: isset($data['message_id']) ? (string) $data['message_id'] : null,
            inReplyTo: isset($data['in_reply_to']) ? (string) $data['in_reply_to'] : null,
            references: self::messageIdList($data['references'] ?? []),
            headers: $headers,
            attachments: self::normaliseAttachments($data['attachments'] ?? []),
            fromName: isset($data['from_name']) ? (string) $data['from_name'] : null,
            cc: $cc,
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
        // Tolerate canonical-case header keys from a direct constructor call, not
        // only the lower-cased keys fromArray() produces.
        $headers = array_change_key_case($this->headers, CASE_LOWER);

        $autoSubmitted = strtolower((string) ($headers['auto-submitted'] ?? ''));
        if ($autoSubmitted !== '' && $autoSubmitted !== 'no') {
            return true;
        }

        $precedence = strtolower((string) ($headers['precedence'] ?? ''));
        if (in_array($precedence, ['bulk', 'auto_reply', 'list', 'junk'], true)) {
            return true;
        }

        foreach (['x-auto-response-suppress', 'x-autoreply', 'x-autorespond', 'list-id', 'list-unsubscribe'] as $header) {
            if (isset($headers[$header]) && trim((string) $headers[$header]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * A delivery report: a bounce (DSN), a read receipt, or anything sent by a
     * mailer daemon. Never a customer message.
     */
    public function isBounce(): bool
    {
        $headers = array_change_key_case($this->headers, CASE_LOWER);

        if (str_contains(strtolower((string) ($headers['content-type'] ?? '')), 'multipart/report')) {
            return true;
        }

        if (trim((string) ($headers['x-failed-recipients'] ?? '')) !== '') {
            return true;
        }

        // RFC 5321: the null reverse-path marks every delivery notification.
        if (isset($headers['return-path']) && trim($headers['return-path']) === '<>') {
            return true;
        }

        return in_array($this->senderLocalPart(), ['mailer-daemon', 'postmaster'], true);
    }

    /**
     * A sender that says nobody reads its replies (noreply@, do-not-reply@, …).
     */
    public function isFromNoReply(): bool
    {
        return preg_match('/^(no|do[-_.]?not)[-_.]?reply\b/', $this->senderLocalPart()) === 1;
    }

    /**
     * Anything that must never open a ticket nor be answered: auto-replies,
     * bulk and list mail, bounces, no-reply senders.
     */
    public function isAutomated(): bool
    {
        return $this->isAutoReply() || $this->isBounce() || $this->isFromNoReply();
    }

    private function senderLocalPart(): string
    {
        $from = strtolower(trim($this->from));
        $at = strrpos($from, '@');

        return $at === false ? $from : substr($from, 0, $at);
    }

    /**
     * Split a References header into individual Message-IDs. The header is a
     * single whitespace-separated string (e.g. "<a@h> <b@h>"); a host may also
     * pass an array, possibly with multi-id elements. Either way, every id is
     * surfaced so header threading can match each ancestor.
     *
     * @return list<string>
     */
    private static function messageIdList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($item)) ?: [] as $id) {
                if ($id !== '') {
                    $out[] = $id;
                }
            }
        }

        return $out;
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
