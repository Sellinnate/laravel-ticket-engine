<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The format a message body is authored in.
 */
enum BodyFormat: string
{
    case Text = 'text';
    case Markdown = 'markdown';
    case Html = 'html';
}
