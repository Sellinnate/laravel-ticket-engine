<?php

declare(strict_types=1);

use Selli\Ticketing\Mail\ReplyParser;

it('cuts the quoted history a mail client appends', function (string $body): void {
    expect((new ReplyParser)->text($body))->toBe("Thanks, that worked!\n\nJane");
})->with([
    'gmail' => "Thanks, that worked!\n\nJane\n\nOn Mon, 5 Oct 2026 at 10:00, Support <support@example.test> wrote:\n> Try restarting it.\n> Bye",
    'wrapped header' => "Thanks, that worked!\n\nJane\n\nOn Mon, 5 Oct 2026 at 10:00, Support Team <support@example.test>\nwrote:\n> Try restarting it.",
    'italian' => "Thanks, that worked!\n\nJane\n\nIl giorno lun 5 ott 2026 alle ore 10:00 Supporto <support@example.test> ha scritto:\n> Prova a riavviarla.",
    'outlook' => "Thanks, that worked!\n\nJane\n\n________________________________\nFrom: Support <support@example.test>\nSent: Monday, October 5, 2026 10:00\nSubject: Re: Printer",
    'outlook headers only' => "Thanks, that worked!\n\nJane\n\nFrom: Support <support@example.test>\nSent: Monday, October 5, 2026 10:00\nTo: Jane",
    'original message' => "Thanks, that worked!\n\nJane\n\n-----Original Message-----\nFrom: Support",
    'signature' => "Thanks, that worked!\n\nJane\n-- \nJane Doe\nAcme Inc.",
    'bottom quote' => "Thanks, that worked!\n\nJane\n\n> Try restarting it.\n> Bye",
    'mobile footer' => "Thanks, that worked!\n\nJane\n\nSent from my iPhone",
    'windows newlines' => "Thanks, that worked!\r\n\r\nJane\r\n\r\nOn Mon, 5 Oct 2026 at 10:00, Support <support@example.test> wrote:\r\n> Try restarting it.",
]);

it('keeps ordinary sentences and inline quotes', function (): void {
    $body = "On Monday the printer stopped. Bob wrote: it is the driver.\n> quoted line\nMy answer below the quote.";

    expect((new ReplyParser)->text($body))->toBe($body);
});

it('keeps the whole body when nothing but quotes would be left', function (): void {
    $forward = "---------- Original Message ----------\nFrom: Bob\nSubject: Invoice";

    expect((new ReplyParser)->text($forward))->toBe($forward);
});

it('cuts the quoted part of HTML mail', function (): void {
    $parser = new ReplyParser;

    expect($parser->html('<div>Thanks!</div><div class="gmail_quote"><blockquote>old</blockquote></div>'))->toBe('<div>Thanks!</div>')
        ->and($parser->html('<p>Ok</p><blockquote type="cite">old</blockquote>'))->toBe('<p>Ok</p>')
        ->and($parser->html('<p>Ok</p><div id="divRplyFwdMsg">From: x</div>'))->toBe('<p>Ok</p>')
        ->and($parser->html('<div class="gmail_quote">only a quote</div>'))->toBe('<div class="gmail_quote">only a quote</div>');
});
