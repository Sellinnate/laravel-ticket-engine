<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * One row per AI turn (a customer message the AI handled): what it did, why it
 * escalated, how confident it was and what it cost. Doubles as the idempotency
 * ledger (one turn per triggering message) and as the source of the AI and
 * knowledge-gap KPIs.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::create($this->table('ai_turns'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'ticket_id');
            $this->foreignId($table, 'conversation_id');
            // "ticket_message:<id>" / "conversation_message:<id>": one turn per trigger.
            $table->string('trigger_key', 80)->unique('ticketing_ai_turns_trigger_unq');
            $table->string('mode', 8);
            $table->string('status', 16)->default('running'); // running | handoff | done
            // How many deliveries worked on the turn (a retry after a crash adds one).
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->string('outcome', 16)->nullable();        // answered | drafted | escalated | skipped | failed
            $table->string('escalation_reason', 32)->nullable();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->unsignedSmallInteger('sources_count')->default(0);
            $table->string('driver', 32);
            $table->string('model', 128)->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 12, 6)->default(0);
            $table->string('currency', 3)->nullable();
            $table->unsignedInteger('latency_ms')->default(0);
            // A short excerpt of the customer question (scrubbed on GDPR erasure)
            // and a normalised fingerprint that groups repeated questions.
            $table->text('question')->nullable();
            $table->string('question_fingerprint', 64)->nullable();
            $table->string('error')->nullable();
            $table->timestamps();

            $table->index('ticket_id', 'ticketing_ai_turns_ticket_idx');
            $table->index('conversation_id', 'ticketing_ai_turns_conv_idx');
            $this->indexScoped($table, ['created_at'], 'ticketing_ai_turns_created_idx');
            $this->indexScoped($table, ['question_fingerprint'], 'ticketing_ai_turns_fingerprint_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ai_turns'));
    }
};
