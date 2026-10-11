<?php

declare(strict_types=1);

namespace Selli\Ticketing\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\KbArticle;
use Selli\Ticketing\Support\Ticketing;
use Selli\Ticketing\Tenancy\TenantContext;

/**
 * Default authorization for knowledge-base articles. Reading follows the
 * article's audience (public, customer, internal); agents of the tenant also see
 * drafts and archived articles. Only agents write, publish and archive.
 */
class KbArticlePolicy
{
    /** Listings are audience-filtered, so anyone may list. */
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, KbArticle $article): bool
    {
        // Another tenant's article is never visible, whoever asks.
        if ($user instanceof Model && ! $this->inUserTenant($user, $article)) {
            return false;
        }

        // Agents of the tenant see everything; an agent who is not one gets no
        // agent privileges from the audience fallback.
        if ($user instanceof CanActOnTickets) {
            return $this->isTenantAgent($user, $article);
        }

        return $article->isVisibleTo(KnowledgeAudience::for($user));
    }

    /**
     * A new article is created in the current tenant: the same rule as any
     * write to an article of that tenant (with no tenant resolved it would be
     * shared, so only an agent tied to no tenant may create it).
     */
    public function create(Authenticatable $user): bool
    {
        $model = Ticketing::kbArticleModel();
        $article = new $model;
        $article->setAttribute($article->getTenantColumn(), app(TenantContext::class)->current());

        return $this->canWrite($user, $article);
    }

    public function update(Authenticatable $user, KbArticle $article): bool
    {
        return $this->canWrite($user, $article);
    }

    public function publish(Authenticatable $user, KbArticle $article): bool
    {
        return $this->canWrite($user, $article);
    }

    public function archive(Authenticatable $user, KbArticle $article): bool
    {
        return $this->canWrite($user, $article);
    }

    public function delete(Authenticatable $user, KbArticle $article): bool
    {
        return $this->canWrite($user, $article);
    }

    /**
     * Writing needs an agent of the article's own tenant. A shared article
     * (null tenant, visible to every tenant) is only writable by an agent who
     * is not tied to one tenant (no or a null tenant column) — never by one
     * tenant's agents.
     */
    protected function canWrite(Authenticatable $user, KbArticle $article): bool
    {
        if (! $user instanceof Model || ! $this->isTenantAgent($user, $article)) {
            return false;
        }

        $column = $article->getTenantColumn();

        if ($article->getAttribute($column) !== null || config('ticketing.tenancy.enabled', true) === false) {
            return true;
        }

        return ! array_key_exists($column, $user->getAttributes()) || $user->getAttribute($column) === null;
    }

    /** An agent (CanActOnTickets) of the article's tenant. */
    protected function isTenantAgent(?Authenticatable $user, KbArticle $article): bool
    {
        return $user instanceof CanActOnTickets
            && $user instanceof Model
            && $this->inUserTenant($user, $article);
    }

    /**
     * The article is shared (null tenant, when sharing is on), the user's model
     * carries no tenant column (tenancy is resolved elsewhere and the query
     * scope already applied), or both tenants match.
     */
    protected function inUserTenant(Model $user, KbArticle $article): bool
    {
        // Single-tenant: there is nothing to separate.
        if (config('ticketing.tenancy.enabled', true) === false) {
            return true;
        }

        $column = $article->getTenantColumn();
        $articleTenant = $article->getAttribute($column);

        if ($articleTenant === null) {
            return config('ticketing.tenancy.allow_shared', true) !== false;
        }

        if (! array_key_exists($column, $user->getAttributes())) {
            return true;
        }

        $userTenant = $user->getAttribute($column);

        return $userTenant !== null && (string) $userTenant === (string) $articleTenant;
    }
}
