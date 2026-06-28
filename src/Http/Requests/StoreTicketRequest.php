<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'priority' => ['nullable', 'integer'],
            'category' => ['nullable', 'string', 'max:255'],
        ];
    }
}
