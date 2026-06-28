<?php

declare(strict_types=1);

namespace Selli\Ticketing\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        $disk = $this->resolveDisk($data->disk);
        $file = $data->file;

        // Store the file BEFORE the transaction and capture its metadata, then
        // delete it if the database write rolls back — so a failed insert can't
        // leave an orphaned blob on the disk.
        $stored = $file->store('ticketing/attachments', $disk);

        if ($stored === false) {
            throw AttachmentRejectedException::storageFailed();
        }

        $path = $stored;
        $name = $file->getClientOriginalName();
        $mime = $file->getMimeType();
        $size = (int) $file->getSize();
        $checksum = hash_file('sha256', $file->getRealPath()) ?: null;

        try {
            /** @var array{0: TicketAttachment, 1: Ticket} $result */
            $result = DB::transaction(function () use ($data, $disk, $path, $name, $mime, $size, $checksum): array {
                // Resolve the owning ticket INSIDE the transaction so a message
                // moved (or a ticket merged away) between the caller loading the
                // attachable and this write can't send tenant/audit/event to the
                // wrong — or a soft-deleted — ticket. ticketOf() re-reads the row
                // and fails closed (findOrFail) if it no longer exists.
                $ticket = $this->ticketOf($data->attachable);
                $model = Ticketing::ticketAttachmentModel();

                /** @var TicketAttachment $attachment */
                $attachment = $model::query()->create(array_merge($ticket->tenantAttributes(), [
                    'attachable_type' => $data->attachable->getMorphClass(),
                    'attachable_id' => $data->attachable->getKey(),
                    'uploaded_by_type' => $data->uploadedBy?->getMorphClass(),
                    'uploaded_by_id' => $data->uploadedBy?->getKey(),
                    'disk' => $disk,
                    'path' => $path,
                    'name' => $name,
                    'mime' => $mime,
                    'size' => $size,
                    'checksum' => $checksum,
                ]));

                $this->audit->record(
                    ticket: $ticket,
                    event: 'attachment.added',
                    actor: $data->uploadedBy,
                    context: ['attachment_id' => $attachment->getKey(), 'name' => $attachment->name],
                );

                return [$attachment, $ticket];
            });
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        [$attachment, $ticket] = $result;

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

    /**
     * Resolve the storage disk, rejecting any request-supplied disk that is not
     * explicitly allowed — so a caller binding the disk from untrusted input
     * cannot redirect a blob onto a public/served disk.
     */
    protected function resolveDisk(?string $requested): string
    {
        $default = (string) config('ticketing.attachments.disk', 'local');

        if ($requested === null || $requested === $default) {
            return $default;
        }

        /** @var list<string> $allowed */
        $allowed = (array) config('ticketing.attachments.allowed_disks', []);

        if (! in_array($requested, $allowed, true)) {
            throw AttachmentRejectedException::disallowedDisk($requested);
        }

        return $requested;
    }

    protected function ticketOf(object $attachable): Ticket
    {
        $ticketModel = Ticketing::ticketModel();

        if ($attachable instanceof Ticket) {
            // Re-read (default scope excludes soft-deleted) so attaching to a
            // ticket that was merged away since the caller loaded it fails closed
            // rather than landing the row on a dead ticket.
            /** @var Ticket $ticket */
            $ticket = $ticketModel::query()->withoutTenancy()->findOrFail($attachable->getKey());

            return $ticket;
        }

        if ($attachable instanceof TicketMessage) {
            // Re-read the message so a caller's stale in-memory copy can't point
            // us at the wrong ticket: if the message was moved (e.g. a split)
            // after the instance was loaded, ticket_id may be out of date and
            // the audit entry / AttachmentAdded event would target the old one.
            $messageModel = Ticketing::ticketMessageModel();
            /** @var TicketMessage $message */
            $message = $messageModel::query()->withoutTenancy()->findOrFail($attachable->getKey());

            /** @var Ticket $ticket */
            $ticket = $ticketModel::query()->withoutTenancy()->findOrFail($message->ticket_id);

            return $ticket;
        }

        throw new \InvalidArgumentException('Attachments can only be added to a Ticket or TicketMessage.');
    }
}
