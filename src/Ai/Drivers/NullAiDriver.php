<?php

declare(strict_types=1);

namespace Selli\Ticketing\Ai\Drivers;

use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiClassification;
use Selli\Ticketing\Ai\AiRequest;
use Selli\Ticketing\Ai\AiSummary;
use Selli\Ticketing\Contracts\AiDriver;

/**
 * No AI: classifies nothing and never answers, so with AI enabled every turn
 * escalates to humans (reason "no_sources"). The safe default.
 */
class NullAiDriver implements AiDriver
{
    public function classify(AiRequest $request): AiClassification
    {
        return AiClassification::none();
    }

    public function answer(AiRequest $request): AiAnswer
    {
        return AiAnswer::none();
    }

    public function summarize(AiRequest $request): AiSummary
    {
        return new AiSummary('');
    }
}
