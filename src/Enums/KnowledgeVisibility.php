<?php

declare(strict_types=1);

namespace Selli\Ticketing\Enums;

/**
 * Who may read a knowledge-base article.
 */
enum KnowledgeVisibility: string
{
    /** Anyone, including an anonymous visitor of an embedded widget. */
    case Public = 'public';

    /** Identified requesters only — optionally one audience (e.g. one client). */
    case Customer = 'customer';

    /** Agents only: runbooks, internal procedures. */
    case Internal = 'internal';
}
