<?php

declare(strict_types=1);

use Selli\Ticketing\Enums\Priority;

it('orders priorities by weight', function (): void {
    expect(Priority::Urgent->isAtLeast(Priority::High))->toBeTrue()
        ->and(Priority::Low->isAtLeast(Priority::Normal))->toBeFalse()
        ->and(Priority::High->isAtLeast(Priority::High))->toBeTrue();
});

it('exposes labelled options', function (): void {
    expect(Priority::options())->toBe([
        10 => 'Low',
        20 => 'Normal',
        30 => 'High',
        40 => 'Urgent',
    ]);
});
