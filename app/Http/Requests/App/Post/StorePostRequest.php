<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use Illuminate\Foundation\Http\FormRequest;

class StorePostRequest extends FormRequest
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
            'status' => ['required', 'in:draft,scheduled,publishing'],
            'content' => ['sometimes', 'nullable', 'string'],
            'media' => ['sometimes', 'array'],
            'scheduled_at' => ['nullable', 'date'],
            'label_ids' => ['sometimes', 'array'],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => ['required', 'uuid'],
            'destinations.*.content_type' => ['required', 'string'],
            'destinations.*.meta' => ['sometimes', 'array'],
            'destinations.*.content' => ['sometimes', 'nullable', 'string'],
            'destinations.*.media' => ['sometimes', 'array'],
            'recover_post_id' => ['sometimes', 'uuid'],
        ];
    }
}
