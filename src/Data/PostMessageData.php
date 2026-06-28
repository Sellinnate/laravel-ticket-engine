<?php

declare(strict_types=1);

namespace Selli\Ticketing\Data;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\MessageSource;
use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Models\Ticket;

/**
 * Typed input for posting a message to a ticket.
 */
final readonly class PostMessageData
{
    /**
     * @param  Model|null  $author  the actor authoring the message (null = system)
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public Ticket $ticket,
        public ?Model $author,
        public string $body,
        public MessageVisibility $visibility = MessageVisibility::Public,
        public BodyFormat $bodyFormat = BodyFormat::Text,
        public MessageSource $source = MessageSource::Api,
        public array $meta = [],
    ) {}
}
