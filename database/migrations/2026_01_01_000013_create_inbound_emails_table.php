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
        // An idempotency ledger for inbound email: the unique message_id is what
        // makes ingestion safe against concurrent/duplicate webhook deliveries —
        // the first delivery wins the insert, any other is a no-op and dropped.
        Schema::create($this->table('inbound_emails'), function (Blueprint $table): void {
            $table->id();
            $table->string('message_id')->unique('ticketing_inbound_emails_msgid_unq');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table('inbound_emails'));
    }
};
