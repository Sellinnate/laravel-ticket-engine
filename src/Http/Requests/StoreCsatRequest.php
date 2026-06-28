<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCsatRequest extends FormRequest
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
            'rating' => ['required', 'integer'],
            'comment' => ['nullable', 'string'],
            'token' => ['nullable', 'string'],
        ];
    }
}
