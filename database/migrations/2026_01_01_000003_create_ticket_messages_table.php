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
        Schema::create($this->table('ticket_messages'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'ticket_id');
            $this->nullableMorph($table, 'author');
            $table->string('visibility')->default('public');
            $table->longText('body');
            $table->string('body_format')->default('text');
            $table->string('source')->default('api');
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('ticket_id');
            $table->index(['ticket_id', 'visibility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('ticket_messages'));
    }
};
