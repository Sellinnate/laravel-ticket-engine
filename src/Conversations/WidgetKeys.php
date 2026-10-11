<?php

declare(strict_types=1);

namespace Selli\Ticketing\Conversations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Selli\Ticketing\Models\WidgetKey;
use Selli\Ticketing\Support\Ticketing;

/**
 * Issues, finds, rotates and revokes widget keys, and verifies signed visitor
 * identities. `Ticketing::widgetKeys()` returns it.
 *
 * ```php
 * ['key' => $key, 'secret' => $secret] = Ticketing::widgetKeys()->create('Acme website', ['https://acme.example']);
 * // $key->public_key goes into the snippet; $secret stays on your server (shown once).
 * ```
 */
class WidgetKeys
{
    /**
     * Create a key in the current tenant. The secret is returned once (it is
     * stored encrypted) — keep it server-side to sign visitor identities.
     *
     * @param  list<string>  $allowedOrigins  e.g. ["https://acme.example"]; "*" allows any origin
     * @param  array<string, mixed>  $settings  greeting, locale, …
     * @return array{key: WidgetKey, secret: string}
     */
    public function create(
        string $name,
        array $allowedOrigins,
        ?Model $audience = null,
        ?string $ticketType = null,
        string $channel = 'widget',
        array $settings = [],
    ): array {
        $secret = self::generateSecret();

        /** @var WidgetKey $key */
        $key = Ticketing::widgetKeyModel()::query()->create(array_filter([
            'name' => $name,
            'public_key' => 'tk_pub_'.Str::random(32),
            'secret' => $secret,
            'allowed_origins' => array_values(array_unique(array_map('trim', $allowedOrigins))),
            'audience_type' => $audience?->getMorphClass(),
            'audience_id' => $audience?->getKey(),
            'ticket_type' => $ticketType,
            'channel' => $channel,
            'settings' => $settings === [] ? null : $settings,
            'is_active' => true,
        ], fn (mixed $value): bool => $value !== null));

        return ['key' => $key, 'secret' => $secret];
    }

    /**
     * A usable key by its public value, across tenants (a widget request has no
     * tenant context yet: the key decides it).
     */
    public function find(string $publicKey): ?WidgetKey
    {
        if (! str_starts_with($publicKey, 'tk_pub_') || strlen($publicKey) > 64) {
            return null;
        }

        $key = Ticketing::widgetKeyModel()::query()->withoutTenancy()
            ->usable()
            ->where('public_key', $publicKey)
            ->first();

        // Exact match: a case-insensitive collation would accept other spellings.
        return $key instanceof WidgetKey && hash_equals($key->public_key, $publicKey) ? $key : null;
    }

    public function revoke(WidgetKey $key): WidgetKey
    {
        $key->forceFill(['is_active' => false, 'revoked_at' => now()])->save();

        return $key;
    }

    /**
     * Replace the signing secret (identities signed with the old one stop
     * verifying). Returns the new secret, once.
     */
    public function rotateSecret(WidgetKey $key): string
    {
        $secret = self::generateSecret();
        $key->forceFill(['secret' => $secret])->save();

        return $secret;
    }

    /**
     * Whether a visitor identity carries a valid signature for this key
     * (constant-time comparison).
     */
    public function verifyIdentity(WidgetKey $key, VisitorIdentity $identity): bool
    {
        $value = $identity->signedValue();

        if ($value === null || $identity->signature === null || ! is_string($key->secret) || $key->secret === '') {
            return false;
        }

        return hash_equals(self::sign($key->secret, $value), strtolower($identity->signature));
    }

    /**
     * The signature a host computes server-side for a visitor.
     */
    public static function sign(string $secret, string $value): string
    {
        return hash_hmac('sha256', $value, $secret);
    }

    protected static function generateSecret(): string
    {
        return 'tk_sec_'.Str::random(48);
    }
}
