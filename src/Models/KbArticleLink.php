<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Enums\ArticleLinkRelation;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A link between a knowledge-base article and a ticket ("resolved with",
 * "suggested", "linked").
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $article_id
 * @property int|string $ticket_id
 * @property ArticleLinkRelation $relation
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KbArticleLink extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'kb_article_links';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'relation' => ArticleLinkRelation::class,
        ];
    }

    /**
     * @return BelongsTo<KbArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Ticketing::kbArticleModel(), 'article_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketModel(), 'ticket_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
