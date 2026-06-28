<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        return [
            'body' => ['required', 'string'],
            'visibility' => ['nullable', Rule::enum(MessageVisibility::class)],
        ];
    }
}
