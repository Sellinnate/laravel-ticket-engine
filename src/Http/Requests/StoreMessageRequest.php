<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Selli\Ticketing\Contracts\CanActOnTickets;
use Selli\Ticketing\Enums\MessageVisibility;

class StoreMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Only agents (CanActOnTickets) may post INTERNAL notes over the API; a
        // requester is limited to public replies, matching the public-only read.
        $allowed = $this->user() instanceof CanActOnTickets
            ? [MessageVisibility::Public->value, MessageVisibility::Internal->value]
            : [MessageVisibility::Public->value];

        return [
            'body' => ['required', 'string'],
            'visibility' => ['nullable', Rule::in($allowed)],
        ];
    }
}
