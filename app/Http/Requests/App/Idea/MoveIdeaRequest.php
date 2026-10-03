<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Models\Idea;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MoveIdeaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('idea'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idea_stage_id' => [
                'nullable',
                'uuid',
                Rule::exists('idea_stages', 'id')->where('workspace_id', $this->user()->current_workspace_id),
            ],
            'idea_ids' => ['required', 'array', 'max:'.Idea::MAX_BATCH],
            'idea_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
