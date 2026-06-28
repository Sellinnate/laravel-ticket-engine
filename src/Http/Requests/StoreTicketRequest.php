<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Selli\Ticketing\Enums\Priority;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // protect the API with your app's auth middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', Rule::enum(Priority::class)],
            'category' => ['nullable', 'string', 'max:255'],
        ];
    }
}
