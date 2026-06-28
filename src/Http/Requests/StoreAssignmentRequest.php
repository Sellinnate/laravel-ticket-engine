<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'team_id' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The team_id must be a scalar value.');
                }
            }],
            'assign_to_me' => ['nullable', 'boolean'],
            'strategy' => ['nullable', Rule::in(['manual', 'round-robin', 'least-busy', 'skill-based'])],
        ];
    }
}
