<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * The channel a ticket arrived through (web, api, email, widget, chat, …), the
 * dimension every per-channel KPI and channel-based routing rule needs.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->string('channel', 32)->nullable();

            $this->indexScoped($table, ['channel', 'created_at'], 'tickets_tenant_channel_idx');
        });
    }

    public function down(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropIndex('tickets_tenant_channel_idx');
            $table->dropColumn('channel');
        });
    }
};
