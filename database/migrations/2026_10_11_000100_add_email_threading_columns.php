<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Selli\Ticketing\Database\Migrations\HasTicketingSchema;
use Selli\Ticketing\Mail\MailMessageIds;

/**
 * Indexed email threading: the Message-ID of the email a message came in with
 * (In-Reply-To / References used to match an unindexed JSON path), backfilled
 * from the messages already stored, and a reference index for the subject
 * fallback ("[SUP-2026-00042]") when the unique key leads with the tenant.
 */
return new class extends Migration
{
    use HasTicketingSchema;

    public function up(): void
    {
        $messages = $this->table('ticket_messages');

        Schema::table($messages, function (Blueprint $table): void {
            $table->string('email_message_id', 191)->nullable();
            $table->index('email_message_id', 'ticket_messages_email_message_id_idx');
        });

        $key = 'id';

        DB::table($messages)
            ->where('source', 'email')
            ->whereNull('email_message_id')
            ->whereNotNull('meta')
            ->select([$key, 'meta'])
            ->chunkById(500, function ($rows) use ($messages, $key): void {
                foreach ($rows as $row) {
                    $meta = is_string($row->meta) ? json_decode($row->meta, true) : null;
                    $id = is_array($meta) && is_string($meta['message_id'] ?? null) ? MailMessageIds::column($meta['message_id']) : '';

                    if ($id !== '') {
                        DB::table($messages)->where($key, $row->{$key})->update(['email_message_id' => $id]);
                    }
                }
            }, $key);

        if ($this->tenancyEnabled()) {
            Schema::table($this->table('tickets'), function (Blueprint $table): void {
                $table->index('reference', 'tickets_reference_idx');
            });
        }
    }

    public function down(): void
    {
        if ($this->tenancyEnabled()) {
            Schema::table($this->table('tickets'), function (Blueprint $table): void {
                $table->dropIndex('tickets_reference_idx');
            });
        }

        Schema::table($this->table('ticket_messages'), function (Blueprint $table): void {
            $table->dropIndex('ticket_messages_email_message_id_idx');
            $table->dropColumn('email_message_id');
        });
    }
};
