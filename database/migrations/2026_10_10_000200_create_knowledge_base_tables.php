<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * The headless help center: categories, articles and FAQs (per locale, grouped
 * into translations), immutable version snapshots, "was this helpful?" votes
 * and article ↔ ticket links.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::create($this->table('kb_categories'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'parent_id');
            $table->string('slug');
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->uniqueScoped($table, ['slug'], 'kb_categories_tenant_slug_unq');
            $table->index(['parent_id', 'position'], 'kb_categories_parent_idx');
        });

        Schema::create($this->table('kb_articles'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'category_id');
            $table->string('type', 16)->default('article');      // article | faq
            $table->string('slug');
            $table->string('locale', 12);
            // sha256 of "<tenant|shared>|<locale>|<slug>": a non-null unique key, so
            // shared (null-tenant) articles cannot duplicate a slug either (NULLs
            // never collide in a composite unique index).
            $table->string('slug_key', 64)->unique('kb_articles_slug_key_unq');
            // Articles that translate each other share a group id.
            $table->string('translation_group', 36)->nullable();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->string('body_format', 16)->default('markdown');
            $table->string('keywords', 1000)->nullable();
            $table->string('status', 16)->default('draft');      // draft | published | archived
            $table->string('visibility', 16)->default('public'); // public | customer | internal
            // Optional audience restriction for "customer" articles (e.g. one client).
            $this->nullableMorph($table, 'audience');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('helpful_count')->default(0);
            $table->unsignedInteger('not_helpful_count')->default(0);
            $this->nullableMorph($table, 'author');
            $table->json('meta')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $this->indexScoped($table, ['slug', 'locale'], 'kb_articles_tenant_slug_idx');
            $this->indexScoped($table, ['status', 'visibility', 'locale'], 'kb_articles_listing_idx');
            $table->index('translation_group', 'kb_articles_translation_idx');
            $table->index('category_id', 'kb_articles_category_idx');
        });

        Schema::create($this->table('kb_article_versions'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'article_id');
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('body');
            $table->string('body_format', 16)->default('markdown');
            $table->string('keywords', 1000)->nullable();
            $this->nullableMorph($table, 'author');
            $table->timestamp('created_at')->nullable();

            $table->unique(['article_id', 'version'], 'kb_article_versions_unq');
        });

        Schema::create($this->table('kb_article_feedback'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'article_id');
            $table->boolean('helpful');
            // A hash identifying the voter (a host model, a contact or an
            // anonymous session) so each voter counts once per article.
            $table->string('voter_key', 64);
            $table->text('comment')->nullable();
            $this->foreignId($table, 'ticket_id');
            $table->timestamps();

            $table->unique(['article_id', 'voter_key'], 'kb_article_feedback_voter_unq');
        });

        Schema::create($this->table('kb_article_links'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'article_id');
            $this->foreignId($table, 'ticket_id');
            $table->string('relation', 16)->default('linked'); // resolved_by | suggested | linked
            $this->nullableMorph($table, 'actor');
            $table->timestamps();

            $table->unique(['article_id', 'ticket_id', 'relation'], 'kb_article_links_unq');
            $table->index('ticket_id', 'kb_article_links_ticket_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('kb_article_links'));
        Schema::dropIfExists($this->table('kb_article_feedback'));
        Schema::dropIfExists($this->table('kb_article_versions'));
        Schema::dropIfExists($this->table('kb_articles'));
        Schema::dropIfExists($this->table('kb_categories'));
    }
};
