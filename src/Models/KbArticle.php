<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\KbArticleFactory;
use Selli\Ticketing\Enums\ArticleStatus;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A knowledge-base article or FAQ in one locale. Translations share a
 * translation_group. Every save through SaveArticle snapshots an immutable
 * KbArticleVersion; only published articles are searchable.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string|null $category_id
 * @property ArticleType $type
 * @property string $slug
 * @property string $slug_key
 * @property string $locale
 * @property string|null $translation_group
 * @property string $title
 * @property string|null $summary
 * @property string $body
 * @property BodyFormat $body_format
 * @property string|null $keywords
 * @property ArticleStatus $status
 * @property KnowledgeVisibility $visibility
 * @property string|null $audience_type
 * @property int|string|null $audience_id
 * @property int $version
 * @property int $helpful_count
 * @property int $not_helpful_count
 * @property string|null $author_type
 * @property int|string|null $author_id
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KbArticle extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<KbArticleFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'kb_articles';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ArticleType::class,
            'status' => ArticleStatus::class,
            'visibility' => KnowledgeVisibility::class,
            'body_format' => BodyFormat::class,
            'version' => 'integer',
            'helpful_count' => 'integer',
            'not_helpful_count' => 'integer',
            'meta' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): KbArticleFactory
    {
        return KbArticleFactory::new();
    }

    /**
     * @return BelongsTo<KbCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Ticketing::kbCategoryModel(), 'category_id');
    }

    /**
     * @return HasMany<KbArticleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Ticketing::kbArticleVersionModel(), 'article_id')->orderBy('version');
    }

    /**
     * @return HasMany<KbArticleFeedback, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(Ticketing::kbArticleFeedbackModel(), 'article_id');
    }

    /**
     * @return HasMany<KbArticleLink, $this>
     */
    public function ticketLinks(): HasMany
    {
        return $this->hasMany(Ticketing::kbArticleLinkModel(), 'article_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function audience(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Other-locale versions of this article (same translation group).
     *
     * @return HasMany<KbArticle, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Ticketing::kbArticleModel(), 'translation_group', 'translation_group')
            ->whereKeyNot($this->getKey());
    }

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published;
    }

    /**
     * Whether the given audience may read this (published) article.
     */
    public function isVisibleTo(KnowledgeAudience $audience): bool
    {
        if (! $this->isPublished() || ! $audience->sees($this->visibility)) {
            return false;
        }

        if ($audience->agent || $this->audience_type === null || $this->audience_id === null) {
            return true;
        }

        return $this->audience_type === $audience->scopeType
            && (string) $this->audience_id === (string) $audience->scopeId;
    }

    /**
     * Share of "helpful" votes (null without votes).
     */
    public function helpfulness(): ?float
    {
        $total = $this->helpful_count + $this->not_helpful_count;

        return $total === 0 ? null : $this->helpful_count / $total;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', ArticleStatus::Published->value);
    }

    /**
     * Published articles the audience may read: its visibilities, and either no
     * audience restriction or the audience's own scope.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeVisibleTo(Builder $query, KnowledgeAudience $audience): Builder
    {
        $query->where('status', ArticleStatus::Published->value)
            ->whereIn('visibility', array_map(fn (KnowledgeVisibility $v): string => $v->value, $audience->visibilities()));

        if ($audience->agent) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($audience): void {
            $scoped->whereNull('audience_type');

            if ($audience->scopeType !== null && $audience->scopeId !== null) {
                $scoped->orWhere(function (Builder $own) use ($audience): void {
                    $own->where('audience_type', $audience->scopeType)
                        ->where('audience_id', (string) $audience->scopeId);
                });
            }
        });
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeInLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeOfType(Builder $query, ArticleType $type): Builder
    {
        return $query->where('type', $type->value);
    }
}
