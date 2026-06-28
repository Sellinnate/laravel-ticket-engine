<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\MessageVisibility;
use Selli\Ticketing\Enums\ParticipantRole;

it('answers visibility questions', function (): void {
    expect(MessageVisibility::Public->isPublic())->toBeTrue()
        ->and(MessageVisibility::Public->isInternal())->toBeFalse()
        ->and(MessageVisibility::Internal->isInternal())->toBeTrue()
        ->and(MessageVisibility::Internal->isPublic())->toBeFalse();
});

it('labels participant roles', function (): void {
    expect(ParticipantRole::Requester->label())->toBe('Requester')
        ->and(ParticipantRole::Cc->label())->toBe('Cc');
});
