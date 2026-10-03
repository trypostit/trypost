<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Models\IdeaStage;
use Illuminate\Foundation\Http\FormRequest;

class ReorderIdeaStagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', IdeaStage::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'stage_ids' => ['required', 'array'],
            'stage_ids.*' => ['required', 'uuid'],
        ];
    }
}
