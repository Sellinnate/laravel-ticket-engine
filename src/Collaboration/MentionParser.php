<?php

declare(strict_types=1);

namespace Selli\Ticketing\Collaboration;

/**
 * Extracts @mention handles from a message body. Agnostic on the user model —
 * resolution happens via a MentionResolver.
 */
class MentionParser
{
    /**
     * @return list<string> unique handles without the leading "@"
     */
    public function extract(string $body): array
    {
        if (preg_match_all('/(?<![\w@])@([\w.\-]{1,64})/', $body, $matches) === false) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}
