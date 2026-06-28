<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAssignmentRequest extends FormRequest
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
            'team_id' => ['nullable'],
            'assign_to_me' => ['nullable', 'boolean'],
            'strategy' => ['nullable', 'string', 'max:255'],
        ];
    }
}
