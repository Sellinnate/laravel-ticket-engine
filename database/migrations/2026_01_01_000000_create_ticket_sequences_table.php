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
        Schema::create($this->table('ticket_sequences'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            // The scope string already encodes the tenant (e.g. "7:SUPPORT-2026"
            // or "shared:SUPPORT-2026"), so a plain non-null unique fully
            // enforces one counter per (tenant, type, year) — independent of how
            // the engine treats NULLs in composite unique indexes.
            $table->string('scope')->unique('ticket_sequences_scope_unq');
            $table->unsignedBigInteger('next_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_sequences'));
    }
};
