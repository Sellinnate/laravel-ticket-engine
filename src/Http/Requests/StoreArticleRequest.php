<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Selli\Ticketing\Enums\ArticleType;
use Selli\Ticketing\Enums\BodyFormat;
use Selli\Ticketing\Enums\KnowledgeVisibility;
use Selli\Ticketing\Support\Ticketing;

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
                // A key of the configured type, naming a category the current
                // tenant can see (another tenant's category does not exist here).
                $valid = is_scalar($value) && (config('ticketing.ids.type') === 'ulid'
                    ? preg_match('/^[0-9A-Za-z]{26}$/', (string) $value) === 1
                    : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false);

                if (! $valid || ! Ticketing::kbCategoryModel()::query()->whereKey((string) $value)->exists()) {
                    $fail('The selected category does not exist.');
                }
            }],
            'visibility' => ['nullable', Rule::enum(KnowledgeVisibility::class)],
            'body_format' => ['nullable', Rule::enum(BodyFormat::class)],
        ];
    }
}
