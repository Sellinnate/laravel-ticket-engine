<?php

declare(strict_types=1);

namespace Selli\Ticketing\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Knowledge\KnowledgeAudience;
use Selli\Ticketing\Models\KbArticle;

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
            && $this->inUserTenant($user, $article);
    }

    /**
     * The article is shared (null tenant, when sharing is on), the user's model
     * carries no tenant column (tenancy is resolved elsewhere and the query
     * scope already applied), or both tenants match.
     */
    protected function inUserTenant(Model $user, KbArticle $article): bool
    {
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
