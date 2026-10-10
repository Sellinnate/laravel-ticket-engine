<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * One "was this helpful?" vote, unique per article and voter.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $article_id
 * @property bool $helpful
 * @property string $voter_key
 * @property string|null $comment
 * @property int|string|null $ticket_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KbArticleFeedback extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'kb_article_feedback';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'helpful' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<KbArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Ticketing::kbArticleModel(), 'article_id');
    }
}
