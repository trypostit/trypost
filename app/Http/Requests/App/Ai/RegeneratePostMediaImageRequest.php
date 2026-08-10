<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use Illuminate\Foundation\Http\FormRequest;

class RegeneratePostMediaImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'instruction' => ['required', 'string', 'max:1000'],
            'regeneration_id' => ['required', 'string', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('instruction')) {
            $this->merge([
                'instruction' => trim((string) $this->input('instruction')),
            ]);
        }
    }
}
