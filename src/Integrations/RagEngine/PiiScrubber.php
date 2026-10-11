<?php

declare(strict_types=1);

namespace Selli\Ticketing\Integrations\RagEngine;

/**
 * Takes the personal data a support conversation typically carries out of
 * the text that goes into the RAG index: email addresses, phone numbers,
 * card-like and IBAN-like numbers, and the names of the people on the ticket.
 * Indexed tickets are knowledge ("how was this solved"), not records of who
 * asked.
 *
 * @internal
 */
class PiiScrubber
{
    /**
     * @param  list<string>  $names  names to replace with "[customer]"
     */
    public function scrub(string $text, array $names = []): string
    {
        $text = (string) preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[email]', $text);
        $text = (string) preg_replace('/\b[A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?\b/', '[iban]', $text);
        $text = (string) preg_replace('/\b(?:\d[ \-]?){13,19}\b/', '[number]', $text);
        $text = (string) preg_replace('/(?<![\w\/.])\+?\d[\d\s().\-]{6,}\d(?!\w|[.\/]\d)/', '[phone]', $text);

        // Longest first: a full name before the parts it contains.
        usort($names, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($names as $name) {
            $name = trim($name);

            if (mb_strlen($name) >= 3) {
                $text = (string) preg_replace('/\b'.preg_quote($name, '/').'\b/iu', '[customer]', $text);
            }
        }

        return $text;
    }
}
