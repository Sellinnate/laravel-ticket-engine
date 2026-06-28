<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Selli\Ticketing\Enums\ParticipantRole;
use Selli\Ticketing\Events\TicketMerged;
use Selli\Ticketing\Events\TicketOpened;
use Selli\Ticketing\Events\TicketSplit;
use Selli\Ticketing\Exceptions\CrossTenantException;
use Selli\Ticketing\Facades\Ticketing;
use Selli\Ticketing\Models\Ticket;
use Selli\Ticketing\Models\TicketAttachment;
use Selli\Ticketing\Models\TicketMessage;
use Selli\Ticketing\Tenancy\TenantContext;

it('merges source tickets into a target', function (): void {
    Event::fake([TicketMerged::class]);

    $target = Ticketing::open(type: 'support', title: 'Target', requester: makeUser());
    $source = Ticketing::open(type: 'support', title: 'Dup', requester: makeUser());
    Ticketing::for($source)->postMessage(makeUser(), 'from the duplicate');

    Ticketing::for($target)->mergeFrom([$source]);

    expect($target->messages()->count())->toBe(1)
        ->and(Ticket::query()->find($source->getKey()))->toBeNull() // soft-deleted
        ->and(Ticket::query()->withTrashed()->find($source->getKey())->trashed())->toBeTrue();

    Event::assertDispatched(TicketMerged::class);
});

it('reflects a merged-in agent reply on the target first response', function (): void {
    $target = Ticketing::open(type: 'support', title: 'T', requester: makeUser());
    $source = Ticketing::open(type: 'support', title: 'S', requester: makeUser());
    Ticketing::for($source)->postMessage(makeUser(['name' => 'Agent']), 'On it'); // public agent reply

    expect($target->fresh()->first_response_at)->toBeNull();

    Ticketing::for($target)->mergeFrom([$source]);

    expect($target->fresh()->first_response_at)->not->toBeNull();
});

it('pulls the target first response earlier when merged history answered sooner', function (): void {
    // Target opens first; the source is answered after the target exists but
    // before the target's own (later) reply.
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'UTC'));
    $target = Ticketing::open(type: 'support', title: 'T', requester: makeUser());

    Carbon::setTestNow(Carbon::parse('2026-06-29 09:30:00', 'UTC'));
    $source = Ticketing::open(type: 'support', title: 'S', requester: makeUser());
    Ticketing::for($source)->postMessage(makeUser(['name' => 'Agent A']), 'early');

    Carbon::setTestNow(Carbon::parse('2026-06-29 10:00:00', 'UTC'));
    Ticketing::for($target)->postMessage(makeUser(['name' => 'Agent B']), 'late');
    $before = $target->fresh()->first_response_at;

    Ticketing::for($target)->mergeFrom([$source]);

    expect($target->fresh()->first_response_at->lessThan($before))->toBeTrue();

    Carbon::setTestNow();
});

it('does not stamp a split first response earlier than the split ticket was created', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-29 09:00:00', 'UTC'));
    $source = Ticketing::open(type: 'support', title: 'src', requester: makeUser());
    $reply = Ticketing::for($source)->postMessage(makeUser(['name' => 'Agent']), 'old reply');

    Carbon::setTestNow(Carbon::parse('2026-06-29 12:00:00', 'UTC')); // split 3h later
    $created = Ticketing::for($source)->split([$reply->getKey()])->fresh();

    expect($created->first_response_at->greaterThanOrEqualTo($created->created_at))->toBeTrue();

    Carbon::setTestNow();
});

it('carries first_response_at onto a split ticket whose moved thread has an agent reply', function (): void {
    $source = Ticketing::open(type: 'support', title: 'Help', requester: makeUser());
    $reply = Ticketing::for($source)->postMessage(makeUser(['name' => 'Agent']), 'On it');

    $created = Ticketing::for($source)->split([$reply->getKey()]);

    expect($created->fresh()->first_response_at)->not->toBeNull();
});

it('does not self-merge and delete the target when a source key is the target with a different type', function (): void {
    $target = Ticketing::open(type: 'support', title: 'Target', requester: makeUser());
    $source = Ticketing::open(type: 'support', title: 'Dup', requester: makeUser());
    Ticketing::for($source)->postMessage(makeUser(), 'from the duplicate');

    // A "ghost" of the target whose key is the string form of the target's id,
    // simulating an int-vs-string key mismatch in the source list.
    $ghost = clone $target;
    $ghost->setAttribute($target->getKeyName(), (string) $target->getKey());

    Ticketing::for($target)->mergeFrom([$ghost, $source]);

    expect(Ticket::query()->find($target->getKey()))->not->toBeNull() // target survives
        ->and(Ticket::query()->withTrashed()->find($target->getKey())->trashed())->toBeFalse()
        ->and($target->messages()->count())->toBe(1); // real source content moved
});

it('refuses to merge tickets across tenants', function (): void {
    $context = app(TenantContext::class);
    $target = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'T', requester: makeUser(['tenant_id' => 5])));
    $source = $context->forTenant(9, fn () => Ticketing::open(type: 'support', title: 'S', requester: makeUser(['tenant_id' => 9])));

    Ticketing::for($target)->mergeFrom([$source]);
})->throws(CrossTenantException::class);

it('splits messages out of a ticket into a new one', function (): void {
    Event::fake([TicketSplit::class]);

    $source = Ticketing::open(type: 'support', title: 'Mixed', requester: makeUser());
    $m1 = Ticketing::for($source)->postMessage(makeUser(), 'keep this');
    $m2 = Ticketing::for($source)->postMessage(makeUser(), 'different request');

    $created = Ticketing::for($source)->split([$m2->getKey()], 'Different request');

    expect($created->messages()->count())->toBe(1)
        ->and((string) TicketMessage::query()->find($m2->getKey())->ticket_id)->toBe((string) $created->getKey())
        ->and((string) TicketMessage::query()->find($m1->getKey())->ticket_id)->toBe((string) $source->getKey())
        ->and($created->links()->count())->toBe(1); // linked back to source

    Event::assertDispatched(TicketSplit::class);
});

it('dispatches TicketSplit with the source reflecting the moved messages', function (): void {
    Event::fake([TicketSplit::class]);

    $source = Ticketing::open(type: 'support', title: 'Mixed', requester: makeUser());
    $m1 = Ticketing::for($source)->postMessage(makeUser(), 'keep this');
    $m2 = Ticketing::for($source)->postMessage(makeUser(), 'move this');

    Ticketing::for($source)->split([$m2->getKey()]);

    Event::assertDispatched(TicketSplit::class, function (TicketSplit $event) use ($source, $m1): bool {
        // The event must carry the reloaded source whose live relation reflects
        // the post-move state (only the kept message remains), not the caller's
        // stale instance.
        return (string) $event->source->getKey() === (string) $source->getKey()
            && $event->source->messages()->count() === 1
            && (string) $event->source->messages()->first()->getKey() === (string) $m1->getKey();
    });
});

it('starts the split ticket fresh at the initial state and fires TicketOpened', function (): void {
    Event::fake([TicketOpened::class]);

    $source = Ticketing::open(type: 'support', title: 'x', requester: makeUser());
    $message = Ticketing::for($source)->postMessage(makeUser(), 'msg');
    Ticketing::for($source)->transition('resolve'); // source becomes resolved

    $created = Ticketing::for($source)->split([$message->getKey()]);

    expect($created->status)->toBe('open') // initial state, not the source's 'resolved'
        ->and($created->resolved_at)->toBeNull();

    Event::assertDispatched(TicketOpened::class, fn (TicketOpened $e): bool => $e->ticket->is($created));
});

it('refuses to split with no matching messages', function (): void {
    $source = Ticketing::open(type: 'support', title: 'x', requester: makeUser());

    Ticketing::for($source)->split([999999]); // no such message on the source
})->throws(InvalidArgumentException::class);

it('carries the requester onto the split ticket', function (): void {
    $requester = makeUser();
    $source = Ticketing::open(type: 'support', title: 'x', requester: $requester);
    $message = Ticketing::for($source)->postMessage(makeUser(), 'msg');

    $created = Ticketing::for($source)->split([$message->getKey()]);

    expect($created->participants()
        ->where('role', ParticipantRole::Requester->value)
        ->where('participant_id', (string) $requester->getKey())
        ->exists())->toBeTrue();
});

it('copies requester participants from sources to the target on merge', function (): void {
    $targetRequester = makeUser(['name' => 'Tgt']);
    $sourceRequester = makeUser(['name' => 'Src']);
    $target = Ticketing::open(type: 'support', title: 'T', requester: $targetRequester);
    $source = Ticketing::open(type: 'support', title: 'S', requester: $sourceRequester);

    Ticketing::for($target)->mergeFrom([$source]);

    expect($target->participants()
        ->where('role', ParticipantRole::Requester->value)
        ->where('participant_id', (string) $sourceRequester->getKey())
        ->exists())->toBeTrue();
});

it('refuses to split a message that belongs to another ticket', function (): void {
    $a = Ticketing::open(type: 'support', title: 'A', requester: makeUser());
    $b = Ticketing::open(type: 'support', title: 'B', requester: makeUser());
    $own = Ticketing::for($a)->postMessage(makeUser(), 'mine');
    $foreign = Ticketing::for($b)->postMessage(makeUser(), 'theirs');

    Ticketing::for($a)->split([$own->getKey(), $foreign->getKey()]);
})->throws(InvalidArgumentException::class);

it('realigns moved content to the target tenant on merge', function (): void {
    // A shared (null-tenant) source merged into a tenant-scoped target: the moved
    // message must adopt the target's tenant so it stays in scope.
    $context = app(TenantContext::class);

    $source = Ticketing::open(type: 'support', title: 'S', requester: makeUser(), attributes: ['tenant_id' => null]);
    $message = Ticketing::for($source)->postMessage(makeUser(), 'shared msg');

    $target = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'T', requester: makeUser(['tenant_id' => 5])));

    Ticketing::for($target)->mergeFrom([$source]);

    expect(TicketMessage::query()->withoutTenancy()->find($message->getKey())->tenant_id)->toBe(5);
});

it('realigns moved message attachments to the target tenant', function (): void {
    $context = app(TenantContext::class);
    Storage::fake('local');

    $source = Ticketing::open(type: 'support', title: 'S', requester: makeUser(), attributes: ['tenant_id' => null]);
    $message = Ticketing::for($source)->postMessage(makeUser(), 'shared');

    $att = Ticketing::addAttachment($message, UploadedFile::fake()->create('a.txt', 1));

    $target = $context->forTenant(5, fn () => Ticketing::open(type: 'support', title: 'T', requester: makeUser(['tenant_id' => 5])));
    Ticketing::for($target)->mergeFrom([$source]);

    expect((string) TicketAttachment::query()->withoutTenancy()->find($att->getKey())->tenant_id)->toBe('5');
});
