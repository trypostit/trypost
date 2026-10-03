<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use Illuminate\Foundation\Http\FormRequest;

class SearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'query' => ['required', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
