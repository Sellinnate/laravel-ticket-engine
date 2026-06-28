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
        Schema::create($this->table('sla_clocks'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'ticket_id');
            $table->string('target'); // first_response | next_response | resolution
            $table->timestamp('started_at');
            $table->timestamp('due_at');
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('remaining_minutes')->nullable(); // captured while paused
            $table->timestamp('breached_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->boolean('threshold_notified')->default(false);
            $table->timestamps();

            $table->unique(['ticket_id', 'target'], 'sla_clocks_ticket_target_unq');
            // Sweep index: clocks still running, ordered by deadline.
            $this->indexScoped($table, ['completed_at', 'due_at'], 'sla_clocks_sweep_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sla_clocks'));
    }
};
