<?php

declare(strict_types=1);

namespace Selli\Ticketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Selli\Ticketing\Concerns\BelongsToTenant;
use Selli\Ticketing\Enums\AiMode;
use Selli\Ticketing\Enums\AiOutcome;
use Selli\Ticketing\Enums\EscalationReason;
use Selli\Ticketing\Models\Concerns\ConfiguresTicketingTable;
use Selli\Ticketing\Support\Ticketing;

/**
 * One AI turn: a customer message the AI handled, with its outcome, the
 * escalation reason, the confidence and the token/cost usage. Unique per
 * triggering message, so a retried job never answers twice.
 *
 * @property int|string $id
 * @property int|string|null $tenant_id
 * @property int|string|null $ticket_id
 * @property int|string|null $conversation_id
 * @property string $trigger_key
 * @property AiMode $mode
 * @property string $status
 * @property int $attempts
 * @property AiOutcome|null $outcome
 * @property EscalationReason|null $escalation_reason
 * @property float|null $confidence
 * @property int $sources_count
 * @property string $driver
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property float $cost
 * @property string|null $currency
 * @property int $latency_ms
 * @property string|null $question
 * @property string|null $question_fingerprint
 * @property string|null $error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiTurn extends Model
{
    use BelongsToTenant;
    use ConfiguresTicketingTable;

    protected $guarded = [];

    protected function tableConfigKey(): string
    {
        return 'ai_turns';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => AiMode::class,
            'outcome' => AiOutcome::class,
            'escalation_reason' => EscalationReason::class,
            'confidence' => 'float',
            'sources_count' => 'integer',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost' => 'float',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticketing::ticketModel(), 'ticket_id');
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Ticketing::conversationModel(), 'conversation_id');
    }
}
