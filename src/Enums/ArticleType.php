<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * A knowledge-base entry is a long-form article or a short question/answer.
 */
enum ArticleType: string
{
    case Article = 'article';
    case Faq = 'faq';
}
