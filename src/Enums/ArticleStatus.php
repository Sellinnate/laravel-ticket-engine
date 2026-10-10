<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * The editorial lifecycle of a knowledge-base article. Only published articles
 * are searchable or visible outside the editors.
 */
enum ArticleStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
