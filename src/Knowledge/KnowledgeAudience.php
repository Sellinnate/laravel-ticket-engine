<?php

declare(strict_types=1);

namespace Selli\Ticketing\Knowledge;

use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Contracts\CanRequestTickets;
use Selli\Ticketing\Enums\KnowledgeVisibility;

/**
 * Who is reading the knowledge base, which decides what they may see:
 *
 * - an anonymous visitor sees public articles;
 * - an identified requester also sees customer articles;
 * - an agent sees everything, internal runbooks included.
 *
 * An optional scope (a host model such as a client) narrows public and
 * customer articles that are restricted to one audience: an article with an
 * audience is only visible within that audience.
 */
final readonly class KnowledgeAudience
{
    private function __construct(
        public bool $agent,
        public bool $identified,
        public ?string $scopeType = null,
        public int|string|null $scopeId = null,
    ) {}

    /** An anonymous visitor (a public website, an embedded widget). */
    public static function public(?Model $scope = null): self
    {
        return new self(false, false, $scope?->getMorphClass(), $scope?->getKey());
    }

    /** An identified requester, optionally within one audience scope. */
    public static function customer(?Model $scope = null): self
    {
        return new self(false, true, $scope?->getMorphClass(), $scope?->getKey());
    }

    /**
     * A public or identified audience within a scope given by its morph type
     * and key (no need to load the host model).
     */
    public static function scoped(?string $scopeType, int|string|null $scopeId, bool $identified = false): self
    {
        return $scopeType !== null && $scopeId !== null
            ? new self(false, $identified, $scopeType, $scopeId)
            : new self(false, $identified);
    }

    /** An agent: every visibility, every audience. */
    public static function agent(): self
    {
        return new self(true, true);
    }

    /**
     * The audience a host actor belongs to: agents (CanActOnTickets) see
     * everything, requesters (CanRequestTickets) see customer articles, anyone
     * else is public.
     */
    public static function for(mixed $actor, ?Model $scope = null): self
    {
        return match (true) {
            $actor instanceof CanActOnTickets => self::agent(),
            $actor instanceof CanRequestTickets => self::customer($scope),
            default => self::public($scope),
        };
    }

    /**
     * @return list<KnowledgeVisibility>
     */
    public function visibilities(): array
    {
        return match (true) {
            $this->agent => KnowledgeVisibility::cases(),
            $this->identified => [KnowledgeVisibility::Public, KnowledgeVisibility::Customer],
            default => [KnowledgeVisibility::Public],
        };
    }

    public function sees(KnowledgeVisibility $visibility): bool
    {
        return in_array($visibility, $this->visibilities(), true);
    }

    /** A stable string for caching and the RAG metadata filter ("Type:id" or "*"). */
    public function scopeKey(): string
    {
        return $this->scopeType !== null && $this->scopeId !== null
            ? $this->scopeType.':'.$this->scopeId
            : '*';
    }
}
