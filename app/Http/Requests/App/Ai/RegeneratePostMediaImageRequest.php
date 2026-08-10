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
     * `regeneration_id` is minted client-side so the frontend can subscribe to
     * the broadcast channel before this request is sent — see
     * PostAiGenerateController / GeneratePostContentRequest for the same pattern.
     *
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
