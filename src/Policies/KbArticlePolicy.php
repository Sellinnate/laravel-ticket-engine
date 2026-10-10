<?php

declare(strict_types=1);

namespace Selli\Ticketing\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Tenancy\TenantGuard;

/**
 * Default authorization for knowledge-base articles. Reading follows the
 * article's audience (public, customer, internal); agents of the tenant also see
 * drafts and archived articles. Only agents write, publish and archive.
 */
class KbArticlePolicy
{
    public function __construct(protected TenantGuard $tenants) {}

    /** Listings are audience-filtered, so anyone may list. */
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, KbArticle $article): bool
    {
        if ($this->isTenantAgent($user, $article)) {
            return true;
        }

        return $article->isVisibleTo(KnowledgeAudience::for($user));
    }

    public function create(Authenticatable $user): bool
    {
        return $user instanceof CanActOnTickets;
    }

    public function update(Authenticatable $user, KbArticle $article): bool
    {
        return $this->isTenantAgent($user, $article);
    }

    public function publish(Authenticatable $user, KbArticle $article): bool
    {
        return $this->isTenantAgent($user, $article);
    }

    public function archive(Authenticatable $user, KbArticle $article): bool
    {
        return $this->isTenantAgent($user, $article);
    }

    public function delete(Authenticatable $user, KbArticle $article): bool
    {
        return $this->isTenantAgent($user, $article);
    }

    /** An agent (CanActOnTickets) of the article's tenant. */
    protected function isTenantAgent(?Authenticatable $user, KbArticle $article): bool
    {
        return $user instanceof CanActOnTickets
            && $user instanceof Model
            && $this->tenants->belongsToTenantOf($user, $article, $article->getTenantColumn());
    }
}
