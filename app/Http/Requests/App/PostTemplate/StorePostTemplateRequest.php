<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostTemplate;

use App\Enums\PostTemplate\Visibility;
use App\Models\PostTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', PostTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emoji' => ['nullable', 'string', 'max:16'],
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:10000'],
            'visibility' => ['required', Rule::enum(Visibility::class)],
        ];
    }
}
