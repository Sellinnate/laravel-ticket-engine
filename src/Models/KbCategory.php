<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * A (nestable) knowledge-base category.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string|null $parent_id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KbCategory extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'kb_categories';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (KbCategory $category): void {
            if (($category->slug ?? '') === '') {
                $category->slug = Str::slug($category->name);
            }
        });
    }

    /**
     * @return BelongsTo<KbCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Ticketing::kbCategoryModel(), 'parent_id');
    }

    /**
     * @return HasMany<KbCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Ticketing::kbCategoryModel(), 'parent_id')->orderBy('position');
    }

    /**
     * @return HasMany<KbArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Ticketing::kbArticleModel(), 'category_id');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
