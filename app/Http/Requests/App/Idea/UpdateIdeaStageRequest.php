<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use Illuminate\Foundation\Http\FormRequest;

class UpdateIdeaStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('ideaStage'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:60'],
        ];
    }
}
