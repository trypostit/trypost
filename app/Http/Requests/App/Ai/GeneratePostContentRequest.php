<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use App\Support\AiPromptRules;
use Illuminate\Foundation\Http\FormRequest;

class GeneratePostContentRequest extends FormRequest
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
            'prompt' => ['required', 'string', 'max:'.AiPromptRules::PROMPT_MAX_LENGTH],
            'current_content' => ['nullable', 'string', 'max:10000'],
        ];
    }
}
