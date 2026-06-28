<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Selli\Ticketing\Events\AttachmentAdded;
use Selli\Ticketing\Exceptions\AttachmentRejectedException;
use Selli\Ticketing\Facades\Ticketing;

beforeEach(fn () => Storage::fake('local'));

it('stores an attachment against a ticket with a checksum', function (): void {
    Event::fake([AttachmentAdded::class]);

    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $file = UploadedFile::fake()->create('report.txt', 10);

    $attachment = Ticketing::for($ticket)->attach($file);

    expect($attachment->name)->toBe('report.txt')
        ->and($attachment->checksum)->not->toBeNull()
        ->and(Storage::disk('local')->exists($attachment->path))->toBeTrue()
        ->and($ticket->attachments()->count())->toBe(1);

    Event::assertDispatched(AttachmentAdded::class);
});

it('rejects an attachment that is too large', function (): void {
    config()->set('ticketing.attachments.max_size_kb', 1);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->attach(UploadedFile::fake()->create('big.txt', 100));
})->throws(AttachmentRejectedException::class);

it('rejects a disallowed mime type', function (): void {
    config()->set('ticketing.attachments.allowed_mimes', ['image/png']);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->attach(UploadedFile::fake()->create('note.txt', 1, 'text/plain'));
})->throws(AttachmentRejectedException::class);

it('rejects a request-supplied disk that is not allow-listed', function (): void {
    Storage::fake('public');
    config()->set('ticketing.attachments.allowed_disks', ['local']);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($ticket)->attach(UploadedFile::fake()->create('evil.svg', 1), disk: 'public');
})->throws(AttachmentRejectedException::class);

it('accepts a request-supplied disk that is allow-listed', function (): void {
    Storage::fake('s3');
    config()->set('ticketing.attachments.allowed_disks', ['local', 's3']);
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    $attachment = Ticketing::for($ticket)->attach(UploadedFile::fake()->create('doc.txt', 1), disk: 's3');

    expect($attachment->disk)->toBe('s3')
        ->and(Storage::disk('s3')->exists($attachment->path))->toBeTrue();
});

it('exposes a download url for the attachment', function (): void {
    $ticket = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $attachment = Ticketing::for($ticket)->attach(UploadedFile::fake()->create('a.txt', 1));

    expect($attachment->temporaryUrl())->toBeString();
});
