<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A public key that lets a chat widget (on the host app or a customer's
 * website) open conversations for one tenant — optionally scoped to one
 * customer. The public key is the only identifier a browser sees; the secret
 * (encrypted at rest, shown once) signs visitor identities.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string $public_key
 * @property string|null $secret
 * @property list<string>|null $allowed_origins
 * @property string|null $audience_type
 * @property int|string|null $audience_id
 * @property string|null $ticket_type
 * @property string $channel
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property Carbon|null $revoked_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WidgetKey extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['secret'];

    protected function tableConfigKey(): string
    {
        return 'widget_keys';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'allowed_origins' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * The customer scope this widget serves (e.g. one client), if any.
     *
     * @return MorphTo<Model, $this>
     */
    public function audience(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Conversation, $this>
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(Ticketing::conversationModel(), 'widget_key_id');
    }

    /**
     * The ticket type this widget's conversations become (the key's own, else
     * `widget.default_type`) — also the type its AI settings are looked up for.
     */
    public function effectiveTicketType(): string
    {
        if ($this->ticket_type !== null && $this->ticket_type !== '') {
            return $this->ticket_type;
        }

        $default = config('ticketing.widget.default_type', 'support');

        return is_string($default) && $default !== '' ? $default : 'support';
    }

    /**
     * The effective ticket type of a conversation's widget key (the default
     * type for a conversation without a key).
     */
    public static function ticketTypeFor(int|string|null $id): string
    {
        $key = $id !== null ? Ticketing::widgetKeyModel()::query()->withoutTenancy()->find($id) : null;

        return $key instanceof self ? $key->effectiveTicketType() : (new self)->effectiveTicketType();
    }

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }

    /**
     * Whether a browser Origin may use this key. Origins are compared on
     * scheme://host[:port], case-insensitively; "*" allows any origin. An empty
     * allow-list allows no browser origin at all (fail closed).
     */
    public function allowsOrigin(string $origin): bool
    {
        $origin = self::normaliseOrigin($origin);

        if ($origin === null) {
            return false;
        }

        foreach ($this->allowed_origins ?? [] as $allowed) {
            if ($allowed === '*' || self::normaliseOrigin($allowed) === $origin) {
                return true;
            }
        }

        return false;
    }

    /**
     * A setting with a default (greeting, locale, …).
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('revoked_at');
    }

    /**
     * "https://Example.com:443/path" → "https://example.com"; null when not an
     * http(s) origin.
     */
    public static function normaliseOrigin(string $origin): ?string
    {
        $parts = parse_url(trim($origin));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        return $scheme.'://'.strtolower($parts['host']).($port !== null && $port !== $default ? ':'.$port : '');
    }
}
