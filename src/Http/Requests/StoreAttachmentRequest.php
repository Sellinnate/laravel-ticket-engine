<?php

declare(strict_types=1);

namespace Selli\Ticketing\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreAttachmentRequest extends FormRequest
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
        $rules = ['required', 'file', 'max:'.max(1, (int) config('ticketing.attachments.max_size_kb', 25600))];

        /** @var list<string> $mimes */
        $mimes = (array) config('ticketing.attachments.allowed_mimes', []);

        if ($mimes !== []) {
            $rules[] = 'mimetypes:'.implode(',', $mimes);
        }

        return ['file' => $rules];
    }
}
