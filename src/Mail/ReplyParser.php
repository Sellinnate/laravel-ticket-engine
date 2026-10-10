<?php

declare(strict_types=1);

namespace Selli\Ticketing\Mail;

/**
 * Keeps what the sender actually wrote in a reply: the quoted history ("On …
 * wrote:", Outlook headers, "> " lines) and the signature are cut off. When
 * nothing would be left (a bare forward), the body is kept whole.
 *
 * @internal
 */
class ReplyParser
{
    /**
     * "On <date>, <name> wrote:" in the languages mail clients use; a digit (the
     * date) is required so an ordinary sentence is never taken for one.
     *
     * @var list<string>
     */
    protected const WROTE = [
        '/^\s*On\b(?=.*\d).{0,300}\bwrote:\s*$/i',
        '/^\s*Il giorno\b(?=.*\d).{0,300}\bha scritto:\s*$/i',
        '/^\s*Am\b(?=.*\d).{0,300}\bschrieb\b.{0,120}:\s*$/i',
        '/^\s*Le\b(?=.*\d).{0,300}\ba écrit\s*:\s*$/iu',
        '/^\s*El\b(?=.*\d).{0,300}\bescribió:\s*$/iu',
    ];

    /**
     * Separator lines that open a forwarded or quoted original.
     *
     * @var list<string>
     */
    protected const SEPARATORS = [
        '/^\s*-{2,}\s*(Original Message|Messaggio originale|Ursprüngliche Nachricht|Message d\'origine|Mensaje original)\s*-{2,}\s*$/iu',
        '/^\s*_{10,}\s*$/',
    ];

    /**
     * Outlook's quoted header block: "From:" followed by "Sent:"/"Date:".
     *
     * @var list<string>
     */
    protected const OUTLOOK_FROM = ['from', 'da', 'von', 'de'];

    /**
     * @var list<string>
     */
    protected const OUTLOOK_DATE = ['sent', 'date', 'inviato', 'data', 'gesendet', 'envoyé', 'enviado'];

    /**
     * Mobile client footers, cut when they end the message.
     */
    protected const MOBILE_FOOTER = '/^\s*(Sent from my \w+|Inviato da(l mio)? \w+|Von meinem \w+ gesendet|Envoyé de mon \w+|Enviado desde mi \w+)/iu';

    /**
     * Markers of the quoted part in HTML mail (Gmail, Apple Mail, Outlook,
     * Thunderbird, Yahoo).
     *
     * @var list<string>
     */
    protected const HTML_MARKERS = [
        '<div class="gmail_quote',
        '<div class="gmail_signature',
        '<blockquote type="cite"',
        '<div id="appendonsend"',
        '<div id="divRplyFwdMsg"',
        '<hr id="stopSpelling"',
        '<div class="yahoo_quoted"',
        '<div class="moz-cite-prefix"',
    ];

    public function text(string $body): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $body));
        $cut = count($lines);

        foreach ($lines as $i => $line) {
            if ($this->opensQuote($lines, $i) || rtrim($line, " \t") === '--' || $line === '-- ') {
                $cut = $i;

                break;
            }
        }

        $kept = array_slice($lines, 0, $cut);

        // A trailing block of "> " lines (bottom-posted quote without header).
        while ($kept !== [] && (trim((string) end($kept)) === '' || str_starts_with(ltrim((string) end($kept)), '>'))) {
            array_pop($kept);
        }

        while ($kept !== [] && preg_match(self::MOBILE_FOOTER, (string) end($kept)) === 1) {
            array_pop($kept);

            while ($kept !== [] && trim((string) end($kept)) === '') {
                array_pop($kept);
            }
        }

        $visible = trim(implode("\n", $kept));

        return $visible === '' ? trim($body) : $visible;
    }

    public function html(string $body): string
    {
        $cut = strlen($body);

        foreach (self::HTML_MARKERS as $marker) {
            $position = stripos($body, $marker);

            if ($position !== false && $position < $cut) {
                $cut = $position;
            }
        }

        $visible = trim(substr($body, 0, $cut));

        return trim(strip_tags($visible)) === '' ? $body : $visible;
    }

    /**
     * @param  list<string>  $lines
     */
    protected function opensQuote(array $lines, int $i): bool
    {
        $line = $lines[$i];

        foreach ([...self::WROTE, ...self::SEPARATORS] as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        // Clients wrap a long "On … wrote:" over two lines.
        $next = trim($lines[$i + 1] ?? '');

        if (trim($line) !== '' && $next !== '' && mb_strlen($next) <= 60 && str_ends_with($next, ':')) {
            foreach (self::WROTE as $pattern) {
                if (preg_match($pattern, rtrim($line).' '.$next) === 1) {
                    return true;
                }
            }
        }

        if (preg_match('/^\s*\*?(\w+)\*?:\s*\S/u', $line, $from) === 1 && in_array(mb_strtolower($from[1]), self::OUTLOOK_FROM, true)) {
            foreach (array_slice($lines, $i + 1, 4) as $next) {
                if (preg_match('/^\s*\*?(\w+)\*?:\s*\S/u', $next, $date) === 1 && in_array(mb_strtolower($date[1]), self::OUTLOOK_DATE, true)) {
                    return true;
                }
            }
        }

        return false;
    }
}
