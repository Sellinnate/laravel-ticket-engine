<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Database\Factories\CannedResponseFactory;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;

/**
 * A reusable reply template with {{placeholder}} tokens.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property string $key
 * @property string $name
 * @property string $body
 * @property int|null $ticket_type_id
 * @property bool $is_active
 */
class CannedResponse extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    /** @use HasFactory<CannedResponseFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'canned_responses';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    protected static function newFactory(): CannedResponseFactory
    {
        return CannedResponseFactory::new();
    }

    /**
     * Render the template, substituting {{dotted.tokens}} from the data map.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(array $data): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            fn (array $matches): string => (string) (Arr::get($data, $matches[1]) ?? ''),
            $this->body,
        );
    }
}
