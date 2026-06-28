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
        Schema::create($this->table('ticket_participants'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'ticket_id');
            $this->requiredMorph($table, 'participant');
            $table->string('role');
            $table->boolean('notify')->default(true);
            $table->timestamps();

            $table->unique(
                ['ticket_id', 'participant_type', 'participant_id', 'role'],
                'ticket_participants_unique'
            );
            $table->index(['ticket_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_participants'));
    }
};
