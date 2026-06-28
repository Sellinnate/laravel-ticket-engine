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
        Schema::create($this->table('business_hours'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            $table->string('timezone')->default('UTC');
            $table->json('schedule'); // [isoWeekday => [[ "09:00","18:00" ], ...]]
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $this->indexScoped($table, ['is_default'], 'business_hours_tenant_default_idx');
        });

        Schema::create($this->table('holidays'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'business_hours_id'); // null = applies to all calendars in tenant
            $table->date('date');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->index(['business_hours_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('holidays'));
        Schema::dropIfExists($this->table('business_hours'));
    }
};
