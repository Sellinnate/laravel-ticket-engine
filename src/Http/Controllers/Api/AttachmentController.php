<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreAttachmentRequest;
use Selli\Ticketing\Models\Ticket;

class AttachmentController
{
    public function store(StoreAttachmentRequest $request, Ticket $ticket): JsonResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');

        $attachment = Ticketing::addAttachment($ticket, $file, uploadedBy: $request->user());

        return response()->json([
            'id' => $attachment->getKey(),
            'name' => $attachment->name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
        ], 201);
    }
}
