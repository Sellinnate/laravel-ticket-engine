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
        Schema::create($this->table('satisfaction_ratings'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'ticket_id');
            $table->string('scale');
            $table->integer('rating')->nullable();          // null until submitted
            $table->longText('comment')->nullable();
            $this->nullableMorph($table, 'submitted_by');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            // One rating row per ticket (re-requested in place on reopen).
            $this->uniqueScoped($table, ['ticket_id'], 'csat_ticket_unq');
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('satisfaction_ratings'));
    }
};
