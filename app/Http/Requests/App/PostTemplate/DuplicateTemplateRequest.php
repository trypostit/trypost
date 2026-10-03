<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostTemplate;

use App\Enums\PostTemplate\Visibility;
use App\Models\PostTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DuplicateTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('postTemplate');

        return $template instanceof PostTemplate
            ? $this->user()->can('update', $template)
            : $this->user()->can('create', PostTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'visibility' => ['required', Rule::enum(Visibility::class)],
        ];
    }

    public function visibility(): Visibility
    {
        return Visibility::from($this->validated('visibility'));
    }
}
