<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Exceptions\ImmutableAuditException;
use Selli\Ticketing\Models\Builders\ImmutableBuilder;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * An immutable snapshot of an article's content at one version. Written by
 * SaveArticle on every content change; never updated afterwards.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string $article_id
 * @property int $version
 * @property string $title
 * @property string|null $summary
 * @property string $body
 * @property BodyFormat $body_format
 * @property string|null $keywords
 * @property string|null $author_type
 * @property int|string|null $author_id
 * @property Carbon|null $created_at
 */
class KbArticleVersion extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'kb_article_versions';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'body_format' => BodyFormat::class,
        ];
    }

    /**
     * @param  Builder  $query
     * @return ImmutableBuilder<static>
     */
    public function newEloquentBuilder($query): ImmutableBuilder
    {
        /** @var ImmutableBuilder<static> $builder */
        $builder = new ImmutableBuilder($query);

        return $builder;
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw ImmutableAuditException::cannotModify();
        });

        static::deleting(function (): never {
            throw ImmutableAuditException::cannotModify();
        });
    }

    /**
     * @return BelongsTo<KbArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Ticketing::kbArticleModel(), 'article_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
