<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Support\PostMediaRules;
use Illuminate\Foundation\Http\FormRequest;

class AttachMediaFromUrlRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'urls' => ['required', 'array', 'min:1', 'max:10'],
            'urls.*.url' => ['required', 'url:http,https', 'active_url'],
            'urls.*.alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ];
    }
}
