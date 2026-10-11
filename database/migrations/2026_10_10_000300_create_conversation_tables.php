<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * The conversational channel: public widget keys, chat conversations (which
 * become tickets when a human is needed) and their messages.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::create($this->table('widget_keys'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            // The only identifier a browser ever sees.
            $table->string('public_key', 64)->unique('ticket_widget_keys_public_unq');
            // HMAC secret for signed visitor identity, encrypted at rest.
            $table->text('secret')->nullable();
            $table->json('allowed_origins')->nullable();
            // Optional customer scope (e.g. the client this widget belongs to).
            $this->nullableMorph($table, 'audience');
            $table->string('ticket_type')->nullable();
            $table->string('channel', 32)->default('widget');
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create($this->table('conversations'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            // Opaque public id (a ULID) — never the primary key.
            $table->string('public_id', 26)->unique('ticket_conversations_public_unq');
            $this->foreignId($table, 'widget_key_id');
            $table->string('channel', 32)->default('widget');
            $this->foreignId($table, 'contact_id');
            $this->nullableMorph($table, 'requester');
            $table->boolean('identity_verified')->default(false);
            // sha256 of the bearer token handed to the client once.
            $table->string('token_hash', 64);
            $table->string('status', 16)->default('open'); // open | escalated | closed
            $this->foreignId($table, 'ticket_id');
            $table->string('locale', 12)->nullable();
            $table->string('origin')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->unsignedSmallInteger('ai_exchanges')->default(0);
            $table->boolean('resolved_by_ai')->default(false);
            $table->string('escalation_reason', 32)->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index('ticket_id', 'ticket_conversations_ticket_idx');
            $this->indexScoped($table, ['status', 'last_activity_at'], 'ticket_conversations_status_idx');
            $this->indexScoped($table, ['created_at'], 'ticket_conversations_created_idx');
        });

        Schema::create($this->table('conversation_messages'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'conversation_id');
            $table->string('role', 16); // visitor | assistant | agent | system
            $table->text('body');
            $this->nullableMorph($table, 'author');
            $table->json('sources')->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at'], 'ticket_conv_messages_conv_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('conversation_messages'));
        Schema::dropIfExists($this->table('conversations'));
        Schema::dropIfExists($this->table('widget_keys'));
    }
};
