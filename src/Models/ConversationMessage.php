<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Enums\ConversationRole;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A message in a conversation, from the visitor, the AI assistant, an agent or
 * the system. Assistant messages carry their sources and confidence.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $conversation_id
 * @property ConversationRole $role
 * @property string $body
 * @property string|null $author_type
 * @property int|string|null $author_id
 * @property list<array<string, mixed>>|null $sources
 * @property float|null $confidence
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ConversationMessage extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'conversation_messages';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ConversationRole::class,
            'sources' => 'array',
            'confidence' => 'float',
            'meta' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Ticketing::conversationModel(), 'conversation_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
