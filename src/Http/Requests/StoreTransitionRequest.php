<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransitionRequest extends FormRequest
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
            'transition' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string'],
        ];
    }
}
