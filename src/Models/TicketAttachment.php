<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\TicketAttachmentFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * A file attached to a ticket or a message, stored on a Laravel disk.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $attachable_type
 * @property int|string $attachable_id
 * @property string|null $uploaded_by_type
 * @property int|string|null $uploaded_by_id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string|null $mime
 * @property int $size
 * @property string|null $checksum
 * @property Carbon|null $scanned_at
 */
class TicketAttachment extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<TicketAttachmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ticket_attachments';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'scanned_at' => 'datetime'];
    }

    protected static function newFactory(): TicketAttachmentFactory
    {
        return TicketAttachmentFactory::new();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * A temporary signed URL for downloading the file (S3-style disks), or a
     * plain URL otherwise.
     */
    public function temporaryUrl(int $minutes = 5): string
    {
        $disk = Storage::disk($this->disk);

        if (method_exists($disk, 'temporaryUrl')) {
            try {
                return $disk->temporaryUrl($this->path, now()->addMinutes($minutes));
            } catch (\Throwable) {
                // Disk driver does not support temporary URLs — fall through.
            }
        }

        return $disk->url($this->path);
    }
}
