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
        Schema::create($this->table('teams'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            $table->string('key')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->indexScoped($table, ['is_active'], 'teams_tenant_active_idx');
        });

        Schema::create($this->table('team_members'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $this->foreignId($table, 'team_id');
            $this->requiredMorph($table, 'member');
            $table->json('skills')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'member_type', 'member_id'], 'team_members_unique');
        });

        Schema::create($this->table('routing_rules'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('name');
            $table->json('conditions')->nullable(); // list of {field, operator, value}
            $this->foreignId($table, 'team_id');
            $this->nullableMorph($table, 'assignee');
            $table->string('strategy')->nullable(); // overrides the default strategy
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $this->indexScoped($table, ['is_active', 'position'], 'routing_rules_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('routing_rules'));
        Schema::dropIfExists($this->table('team_members'));
        Schema::dropIfExists($this->table('teams'));
    }
};
