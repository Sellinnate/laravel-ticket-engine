<?php

declare(strict_types=1);

namespace Selli\Ticketing\Data;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\Priority;

/**
 * Typed input for opening a ticket. No magic arrays.
 */
final readonly class OpenTicketData
{
    /**
     * @param  Model|null  $requester  the actor opening the ticket (host model)
     * @param  Model|null  $subject  the host entity the ticket is about (nullable)
     * @param  array<string, mixed>  $attributes  extra column values & custom_fields
     */
    public function __construct(
        public string $type,
        public string $title,
        public ?Model $requester = null,
        public ?Priority $priority = null,
        public ?Model $subject = null,
        public ?string $category = null,
        public array $attributes = [],
        public int|string|null $tenantId = null,
    ) {}
}
