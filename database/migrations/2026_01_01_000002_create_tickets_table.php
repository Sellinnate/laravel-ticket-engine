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
        Schema::create($this->table('tickets'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('reference');
            $this->foreignId($table, 'ticket_type_id');
            $this->nullableMorph($table, 'subject');
            $table->string('category')->nullable();
            $table->unsignedSmallInteger('priority')->default(20);
            $table->string('status')->index();
            $this->nullableMorph($table, 'assignee');
            $this->foreignId($table, 'team_id');
            $table->string('title');
            $table->json('custom_fields')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->unsignedInteger('reopened_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $this->uniqueScoped($table, ['reference'], 'tickets_tenant_reference_unq');
            $this->indexScoped($table, ['status', 'due_at'], 'tickets_tenant_status_due_idx');
            $this->indexScoped($table, ['ticket_type_id', 'status'], 'tickets_tenant_type_status_idx');
            $this->indexScoped($table, ['subject_type', 'subject_id'], 'tickets_tenant_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('tickets'));
    }
};
