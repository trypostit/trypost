<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostTemplate;

use App\Models\PostTemplate;
use Illuminate\Foundation\Http\FormRequest;

class TemplatePickerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', PostTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function search(): ?string
    {
        $search = trim(mb_substr((string) $this->validated('search'), 0, 100));

        return $search === '' ? null : $search;
    }
}
