<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Models\Idea;
use App\Support\AiPromptRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateIdeasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Idea::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'between:1,10'],
            'business' => ['required', 'string', 'max:1000'],
            'audience' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:'.AiPromptRules::PROMPT_MAX_LENGTH],
            'idea_stage_id' => [
                'nullable',
                'uuid',
                Rule::exists('idea_stages', 'id')->where('workspace_id', $this->user()->current_workspace_id),
            ],
        ];
    }
}
