<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Http\Requests\StoreAttachmentRequest;

class AttachmentController extends Controller
{
    public function store(StoreAttachmentRequest $request, string $ticket): JsonResponse
    {
        $ticket = $this->resolveTicket($ticket);
        $this->authorizeTicket($request->user(), 'addAttachment', $ticket);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $attachment = $this->guard('file', fn () => Ticketing::addAttachment($ticket, $file, uploadedBy: $request->user()));

        return response()->json([
            'id' => $attachment->getKey(),
            'name' => $attachment->name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
        ], 201);
    }
}
