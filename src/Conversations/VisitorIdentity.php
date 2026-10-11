<?php

declare(strict_types=1);

namespace Selli\Ticketing\Conversations;

use Selli\Ticketing\Contacts\ContactDirectory;

/**
 * Who a widget visitor claims to be. Only a valid HMAC signature makes the
 * claim trustworthy: the host signs `user_id` (or, without one, the lower-cased
 * email) with the widget key's secret on its server —
 * `hash_hmac('sha256', $userIdOrEmail, $secret)` — and passes it to the widget.
 */
final readonly class VisitorIdentity
{
    public function __construct(
        public ?string $email = null,
        public ?string $name = null,
        public ?string $userId = null,
        public ?string $signature = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $string = fn (string $key): ?string => is_scalar($data[$key] ?? null) && trim((string) $data[$key]) !== ''
            ? mb_substr(trim((string) $data[$key]), 0, 255)
            : null;

        return new self($string('email'), $string('name'), $string('user_id'), $string('signature'));
    }

    /**
     * The value the signature covers: the host user id, else the email.
     */
    public function signedValue(): ?string
    {
        return $this->userId ?? ContactDirectory::normaliseEmail($this->email);
    }
}
