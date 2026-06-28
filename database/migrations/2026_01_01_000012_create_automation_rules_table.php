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
        Schema::create($this->table('automation_rules'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            $table->string('event');                       // trigger key, e.g. "ticket.opened"
            $table->string('match')->default('all');       // "all" | "any"
            $table->json('conditions')->nullable();         // [{field, operator, value}]
            $table->json('actions')->nullable();            // [{type, ...}]
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);        // lower runs first
            $table->boolean('stop_processing')->default(false);
            $table->timestamps();

            $this->indexScoped($table, ['event', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('automation_rules'));
    }
};
