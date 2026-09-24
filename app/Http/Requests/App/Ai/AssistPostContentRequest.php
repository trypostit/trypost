<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use App\Enums\Ai\PostAssistantMode;
use App\Support\AiPromptRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssistPostContentRequest extends FormRequest
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
            'mode' => ['required', Rule::enum(PostAssistantMode::class)],
            'current_content' => [
                Rule::requiredIf(fn (): bool => PostAssistantMode::tryFrom((string) $this->input('mode'))?->requiresContent() ?? false),
                'nullable',
                'string',
                'max:10000',
            ],
            'prompt' => [
                Rule::requiredIf(fn (): bool => $this->input('mode') === PostAssistantMode::WriteMore->value),
                'nullable',
                'string',
                'max:'.AiPromptRules::PROMPT_MAX_LENGTH,
            ],
        ];
    }
}
