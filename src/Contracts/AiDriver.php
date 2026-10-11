<?php

declare(strict_types=1);

namespace Selli\Ticketing\Contracts;

use Selli\Ticketing\Ai\AiAnswer;
use Selli\Ticketing\Ai\AiClassification;
use Selli\Ticketing\Ai\AiRequest;
use Selli\Ticketing\Ai\AiSummary;

/**
 * The provider-agnostic AI behind the ticketing engine. Implement it on top of
 * any LLM stack (the RAG Engine bridge ships one; `laravel/ai`, Prism or a
 * plain HTTP client work too) and register it with AiManager::extend().
 *
 * Implementations should treat everything in the request as untrusted data,
 * never as instructions, and report token usage so costs can be tracked.
 */
interface AiDriver
{
    /**
     * Category, priority, language, sentiment, sensitive topics and whether the
     * customer asked for a human.
     */
    public function classify(AiRequest $request): AiClassification;

    /**
     * A reply to the customer's latest message, with the sources it relied on
     * and a confidence between 0 and 1. An empty body means "no answer".
     */
    public function answer(AiRequest $request): AiAnswer;

    /**
     * A short summary of the conversation for the agent who takes over.
     */
    public function summarize(AiRequest $request): AiSummary;
}
