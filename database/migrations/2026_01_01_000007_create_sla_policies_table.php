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
        Schema::create($this->table('sla_policies'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            $this->foreignId($table, 'ticket_type_id');      // null = any type (catch-all)
            $table->unsignedSmallInteger('priority')->nullable(); // null = any priority
            $table->unsignedInteger('first_response_minutes')->nullable();
            $table->unsignedInteger('next_response_minutes')->nullable();
            $table->unsignedInteger('resolution_minutes')->nullable();
            $this->foreignId($table, 'business_hours_id');    // null = 24/7
            $table->json('pause_in_states')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->indexScoped($table, ['ticket_type_id', 'priority'], 'sla_policies_match_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('sla_policies'));
    }
};
