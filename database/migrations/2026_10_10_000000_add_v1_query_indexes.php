<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * Indexes for the v1 listing, assignment-load and reporting queries. Every one
 * leads with the tenant column (when tenancy is on) because every read is
 * tenant-scoped by the global scope.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            // Default listing order (latest first) and period-bounded reports.
            $this->indexScoped($table, ['created_at'], 'tickets_tenant_created_idx');
            // An agent's queue and the least-busy / skill-based load counts.
            // Status is deliberately left out: with a string tenant column on
            // MySQL utf8mb4, four VARCHAR(255) parts exceed InnoDB's 3072-byte
            // key limit; three fit (3060 bytes) and already narrow to one agent.
            $this->indexScoped($table, ['assignee_type', 'assignee_id'], 'tickets_tenant_assignee_idx');
            // A team's queue.
            $this->indexScoped($table, ['team_id', 'status'], 'tickets_tenant_team_idx');
            // Resolution-time and per-category reports.
            $this->indexScoped($table, ['resolved_at'], 'tickets_tenant_resolved_idx');
            $this->indexScoped($table, ['category', 'created_at'], 'tickets_tenant_category_idx');
        });

        Schema::table($this->table('ticket_participants'), function (Blueprint $table): void {
            // "Tickets I'm on" (requester listing, GDPR export/anonymisation).
            $table->index(['participant_type', 'participant_id', 'role'], 'ticket_participants_actor_idx');
        });

        Schema::table($this->table('ticket_messages'), function (Blueprint $table): void {
            // A ticket's conversation in order (and its earliest agent reply).
            $table->index(['ticket_id', 'created_at'], 'ticket_messages_ticket_created_idx');
        });

        Schema::table($this->table('sla_clocks'), function (Blueprint $table): void {
            // SLA compliance per target over a period.
            $this->indexScoped($table, ['target', 'started_at'], 'sla_clocks_tenant_target_idx');
        });

        Schema::table($this->table('satisfaction_ratings'), function (Blueprint $table): void {
            // CSAT over a period.
            $this->indexScoped($table, ['submitted_at'], 'csat_tenant_submitted_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('satisfaction_ratings'), function (Blueprint $table): void {
            $table->dropIndex('csat_tenant_submitted_idx');
        });

        Schema::table($this->table('sla_clocks'), function (Blueprint $table): void {
            $table->dropIndex('sla_clocks_tenant_target_idx');
        });

        Schema::table($this->table('ticket_messages'), function (Blueprint $table): void {
            $table->dropIndex('ticket_messages_ticket_created_idx');
        });

        Schema::table($this->table('ticket_participants'), function (Blueprint $table): void {
            $table->dropIndex('ticket_participants_actor_idx');
        });

        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropIndex('tickets_tenant_category_idx');
            $table->dropIndex('tickets_tenant_resolved_idx');
            $table->dropIndex('tickets_tenant_team_idx');
            $table->dropIndex('tickets_tenant_assignee_idx');
            $table->dropIndex('tickets_tenant_created_idx');
        });
    }
};
