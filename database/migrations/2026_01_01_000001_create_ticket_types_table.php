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
        Schema::create($this->table('ticket_types'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('key');
            $table->string('name');
            $table->string('workflow')->default('default');
            $table->unsignedSmallInteger('default_priority')->default(20);
            $table->json('custom_fields_schema')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->uniqueScoped($table, ['key'], 'ticketing_types_tenant_key_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_types'));
    }
};
