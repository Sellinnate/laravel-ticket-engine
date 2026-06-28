<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Support\Facades\DB;
use Selli\Ticketing\Data\AddAttachmentData;
use Selli\Ticketing\Events\AttachmentAdded;
use Selli\Ticketing\Exceptions\AttachmentRejectedException;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketAttachment;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Support\AuditLogger;
use Selli\Ticketing\Support\Ticketing;

/**
 * Validates and stores an attachment against a ticket or message, recording its
 * checksum and emitting {@see AttachmentAdded}.
 */
class AddAttachment
{
    public function __construct(protected AuditLogger $audit) {}

    public function handle(AddAttachmentData $data): TicketAttachment
    {
        $this->validate($data);

        $disk = $data->disk ?? (string) config('ticketing.attachments.disk', 'local');
        $file = $data->file;
        $ticket = $this->ticketOf($data->attachable);

        $attachment = DB::transaction(function () use ($data, $disk, $file, $ticket): TicketAttachment {
            $path = $file->store('ticketing/attachments', $disk);

            $model = Ticketing::ticketAttachmentModel();

            /** @var TicketAttachment $attachment */
            $attachment = $model::query()->create(array_merge($ticket->tenantAttributes(), [
                'attachable_type' => $data->attachable->getMorphClass(),
                'attachable_id' => $data->attachable->getKey(),
                'uploaded_by_type' => $data->uploadedBy?->getMorphClass(),
                'uploaded_by_id' => $data->uploadedBy?->getKey(),
                'disk' => $disk,
                'path' => (string) $path,
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()) ?: null,
            ]));

            $this->audit->record(
                ticket: $ticket,
                event: 'attachment.added',
                actor: $data->uploadedBy,
                context: ['attachment_id' => $attachment->getKey(), 'name' => $attachment->name],
            );

            return $attachment;
        });

        AttachmentAdded::dispatch($ticket, $attachment);

        return $attachment;
    }

    protected function validate(AddAttachmentData $data): void
    {
        $maxKb = (int) config('ticketing.attachments.max_size_kb', 25600);

        if ($maxKb > 0 && $data->file->getSize() > $maxKb * 1024) {
            throw AttachmentRejectedException::tooLarge($maxKb);
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('ticketing.attachments.allowed_mimes', []);
        $mime = (string) $data->file->getMimeType();

        if ($allowed !== [] && ! in_array($mime, $allowed, true)) {
            throw AttachmentRejectedException::disallowedMime($mime);
        }
    }

    protected function ticketOf(object $attachable): Ticket
    {
        if ($attachable instanceof Ticket) {
            return $attachable;
        }

        if ($attachable instanceof TicketMessage) {
            $model = Ticketing::ticketModel();
            /** @var Ticket $ticket */
            $ticket = $model::query()->withoutTenancy()->findOrFail($attachable->ticket_id);

            return $ticket;
        }

        throw new \InvalidArgumentException('Attachments can only be added to a Ticket or TicketMessage.');
    }
}
