<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TagFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * A free per-tenant tag, attachable to tickets (or any model) via the taggables
 * pivot.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $name
 * @property string $slug
 */
class Tag extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TagFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'tags';
    }

    protected static function booted(): void
    {
        static::creating(function (Tag $tag): void {
            if (($tag->slug ?? '') === '') {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    protected static function newFactory(): TagFactory
    {
        return TagFactory::new();
    }
}
