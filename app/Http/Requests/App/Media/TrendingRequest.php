<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use Illuminate\Foundation\Http\FormRequest;

class TrendingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
