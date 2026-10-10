<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * Classification and AI-handling state on tickets, and a team-lead role on
 * memberships (team leads are notified when the AI escalates).
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->string('language', 12)->nullable();
            $table->string('sentiment', 16)->nullable();
            // null (AI never involved) | active | escalated | suspended | resolved
            $table->string('ai_status', 16)->nullable();
            $table->unsignedSmallInteger('ai_exchanges')->default(0);
            $table->string('escalation_reason', 32)->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('first_ai_response_at')->nullable();

            $this->indexScoped($table, ['escalated_at'], 'tickets_tenant_escalated_idx');
        });

        Schema::table($this->table('team_members'), function (Blueprint $table): void {
            $table->string('role', 16)->default('member'); // member | lead
        });
    }

    public function down(): void
    {
        Schema::table($this->table('team_members'), function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        Schema::table($this->table('tickets'), function (Blueprint $table): void {
            $table->dropIndex('tickets_tenant_escalated_idx');
            $table->dropColumn([
                'language', 'sentiment', 'ai_status', 'ai_exchanges',
                'escalation_reason', 'escalated_at', 'first_ai_response_at',
            ]);
        });
    }
};
