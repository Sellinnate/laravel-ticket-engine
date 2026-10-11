<?php

declare(strict_types=1);

use Selli\Ticketing\Integrations\RagEngine\PiiScrubber;

it('takes the personal data out of indexed text', function (): void {
    $text = 'I am Jane Doe (jane.doe@example.com, +39 333 123 4567). Card 4242 4242 4242 4242, IBAN IT60 X054 2811 1010 0000 0123 456. Order 2026/118 failed at 10:30 on version 2.1.4.';

    expect((new PiiScrubber)->scrub($text, ['Jane Doe', 'Jo']))
        ->toBe('I am [customer] ([email], [phone]). Card [number], IBAN [iban]. Order 2026/118 failed at 10:30 on version 2.1.4.');
});

it('scrubs a phone number at the end of a sentence', function (): void {
    expect((new PiiScrubber)->scrub('Call me on +39 333 1234567. Thanks'))->toBe('Call me on [phone]. Thanks');
});

it('replaces a full name before the parts it contains', function (): void {
    expect((new PiiScrubber)->scrub('Ann-Marie wrote, then Ann replied.', ['Ann', 'Ann-Marie']))->toBe('[customer] wrote, then [customer] replied.');
});
