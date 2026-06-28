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

            $table->string('scope');
            $table->unsignedBigInteger('next_value')->default(0);
            $table->timestamps();

            $this->uniqueScoped($table, ['scope'], 'ticket_sequences_tenant_scope_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_sequences'));
    }
};
