<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Enums\ConversationStatus;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A chat/widget conversation. The AI answers it while it is open; when a human
 * is needed it becomes a ticket (escalated) and continues there.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $public_id
 * @property int|string|null $widget_key_id
 * @property string $channel
 * @property int|string|null $contact_id
 * @property string|null $requester_type
 * @property int|string|null $requester_id
 * @property bool $identity_verified
 * @property string $token_hash
 * @property ConversationStatus $status
 * @property int|string|null $ticket_id
 * @property string|null $locale
 * @property string|null $origin
 * @property string|null $ip_hash
 * @property int $message_count
 * @property int $ai_exchanges
 * @property bool $resolved_by_ai
 * @property EscalationReason|null $escalation_reason
 * @property Carbon|null $escalated_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $last_activity_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Conversation extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['token_hash', 'ip_hash'];

    protected function tableConfigKey(): string
    {
        return 'conversations';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'escalation_reason' => EscalationReason::class,
            'identity_verified' => 'boolean',
            'resolved_by_ai' => 'boolean',
            'message_count' => 'integer',
            'ai_exchanges' => 'integer',
            'meta' => 'array',
            'escalated_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ConversationMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Ticketing::conversationMessageModel(), 'conversation_id');
    }

    /**
     * @return BelongsTo<WidgetKey, $this>
     */
    public function widgetKey(): BelongsTo
    {
        return $this->belongsTo(Ticketing::widgetKeyModel(), 'widget_key_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Ticketing::contactModel(), 'contact_id');
    }

    /**
     * The identified host requester (signed identity resolved to a host model).
     *
     * @return MorphTo<Model, $this>
     */
    public function requester(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketModel(), 'ticket_id');
    }

    public function isOpen(): bool
    {
        return $this->status === ConversationStatus::Open;
    }

    /**
     * Constant-time check of a bearer token against the stored hash.
     */
    public function matchesToken(string $token): bool
    {
        return hash_equals($this->token_hash, hash('sha256', $token));
    }
}
