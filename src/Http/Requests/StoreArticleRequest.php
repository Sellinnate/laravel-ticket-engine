<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;

/**
 * @internal
 */
class StoreArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller authorizes against the KbArticlePolicy
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');

        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'body' => [$creating ? 'required' : 'sometimes', 'string', 'max:200000'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', Rule::enum(ArticleType::class)],
            'locale' => ['nullable', 'string', 'max:12'],
            'slug' => ['nullable', 'string', 'max:180'],
            'keywords' => ['nullable', 'array', 'max:30'],
            'keywords.*' => ['string', 'max:60'],
            'category_id' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_scalar($value)) {
                    $fail('The category id must be a scalar value.');
                }
            }],
            'visibility' => ['nullable', Rule::enum(KnowledgeVisibility::class)],
            'body_format' => ['nullable', Rule::enum(BodyFormat::class)],
        ];
    }
}
