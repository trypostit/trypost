<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use App\Enums\Ai\PostAssistantMode;
use App\Enums\SocialAccount\Platform;
use App\Rules\PostContentFitsMaxLength;
use App\Rules\PromptHasMinimumWords;
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
        $mode = PostAssistantMode::tryFrom((string) $this->input('mode'));

        return [
            'mode' => ['required', Rule::enum(PostAssistantMode::class)],
            'prompt' => [
                Rule::requiredIf(fn (): bool => $mode?->requiresPrompt() ?? false),
                'nullable',
                'string',
                'max:'.AiPromptRules::PROMPT_MAX_LENGTH,
                new PromptHasMinimumWords,
            ],
            'current_content' => [
                Rule::requiredIf(fn (): bool => $mode?->requiresContent() ?? false),
                'nullable',
                'string',
                new PostContentFitsMaxLength,
            ],
            'previous_content' => ['nullable', 'string', new PostContentFitsMaxLength],
            'platform' => ['nullable', Rule::enum(Platform::class)],
            'social_account_id' => [
                'nullable',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $this->user()->currentWorkspace->id),
            ],
        ];
    }
}
