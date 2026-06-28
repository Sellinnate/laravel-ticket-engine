<?php

declare(strict_types=1);

namespace Selli\Ticketing\Data;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Typed input for adding an attachment to a ticket or message.
 */
final readonly class AddAttachmentData
{
    public function __construct(
        public Model $attachable,   // Ticket or TicketMessage
        public UploadedFile $file,
        public ?string $disk = null,
        public ?Model $uploadedBy = null,
    ) {}
}
