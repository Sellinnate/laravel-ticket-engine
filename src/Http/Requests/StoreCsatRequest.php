<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Selli\Ticketing\Support\Csat;

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
        $scale = Csat::scale();

        return [
            'rating' => ['required', 'integer', 'between:'.$scale->min().','.$scale->max()],
            'comment' => ['nullable', 'string'],
            'token' => ['nullable', 'string'],
        ];
    }
}
