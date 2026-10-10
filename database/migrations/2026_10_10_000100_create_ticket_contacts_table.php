<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;

/**
 * External requesters with no account in the host app (an email sender, an
 * anonymous widget visitor who left a name and an email), de-duplicated per
 * tenant on the normalised email.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        Schema::create($this->table('contacts'), function (Blueprint $table): void {
            $this->primaryKey($table);
            $this->tenantColumn($table);

            $table->string('email')->nullable();
            // sha256 of "<tenant|shared>|<normalised email>": a non-null unique key
            // that de-duplicates even when the tenant is NULL (NULLs never collide
            // in a composite unique index). Cleared on anonymisation, so the
            // unique index below must admit many NULLs.
            $table->string('dedupe_key', 64)->nullable();
            $table->string('name')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('locale', 12)->nullable();
            // Optional link to a host model (e.g. the user the contact became).
            $this->nullableMorph($table, 'user');
            $table->json('meta')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();

            $this->indexScoped($table, ['email'], 'ticket_contacts_tenant_email_idx');
        });

        $this->uniqueUnlessNull($this->table('contacts'), 'dedupe_key', 'ticket_contacts_dedupe_unq');
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('contacts'));
    }
};
