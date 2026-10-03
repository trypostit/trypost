<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostTemplate;

use App\Enums\PostTemplate\Visibility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('postTemplate'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['sometimes', 'nullable', 'string', 'max:16'],
            'title' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'body' => ['sometimes', 'required', 'string', 'max:10000'],
            'visibility' => ['sometimes', 'required', Rule::enum(Visibility::class)],
        ];
    }
}
