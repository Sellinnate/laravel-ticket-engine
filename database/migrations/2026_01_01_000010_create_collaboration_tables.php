<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::create($this->table('ticket_attachments'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->requiredMorph($table, 'attachable'); // ticket or message
            $this->nullableMorph($table, 'uploaded_by');
            $table->string('disk');
            $table->string('path');
            $table->string('name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('checksum')->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();
        });

        Schema::create($this->table('canned_responses'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('key');
            $table->string('name');
            $table->longText('body');
            $this->foreignId($table, 'ticket_type_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->uniqueScoped($table, ['key'], 'canned_responses_tenant_key_unq');
        });

        Schema::create($this->table('macros'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('key');
            $table->string('name');
            $table->json('actions'); // {transition, assign_team_id, reply, tags, ...}
            $this->foreignId($table, 'ticket_type_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->uniqueScoped($table, ['key'], 'macros_tenant_key_unq');
        });

        Schema::create($this->table('tags'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $this->uniqueScoped($table, ['slug'], 'tags_tenant_slug_unq');
        });

        // Pure join table: tenancy is implied by the (tenant-scoped) tag and the
        // (tenant-scoped) taggable, so no tenant column is needed here.
        Schema::create($this->table('taggables'), function (Blueprint $table): void {
            $this->primaryKey($table);

            $this->foreignId($table, 'tag_id');
            $this->requiredMorph($table, 'taggable');
            $table->timestamps();

            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'taggables_unique');
        });

        Schema::create($this->table('ticket_links'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'ticket_id');
            $this->requiredMorph($table, 'linkable');
            $table->string('role')->default('references'); // affects | references | caused_by
            $table->timestamps();

            $table->index(['ticket_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_links'));
        Schema::dropIfExists($this->table('taggables'));
        Schema::dropIfExists($this->table('tags'));
        Schema::dropIfExists($this->table('macros'));
        Schema::dropIfExists($this->table('canned_responses'));
        Schema::dropIfExists($this->table('ticket_attachments'));
    }
};
