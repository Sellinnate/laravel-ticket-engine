<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai;

use Selli\Ticketing\Enums\Priority;
use Selli\Ticketing\Enums\Sentiment;

/**
 * What the AI understood of a request. Every field is optional: a driver fills
 * what it can.
 */
final readonly class AiClassification
{
    /**
     * @param  list<string>  $topics  e.g. "billing", "privacy" — matched against the sensitive topics
     */
    public function __construct(
        public ?string $category = null,
        public ?Priority $priority = null,
        public ?string $language = null,
        public ?Sentiment $sentiment = null,
        public bool $wantsHuman = false,
        public array $topics = [],
        public AiUsage $usage = new AiUsage,
    ) {}

    public static function none(?AiUsage $usage = null): self
    {
        return new self(usage: $usage ?? AiUsage::none());
    }
}
