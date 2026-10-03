<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Http\Requests\App\Idea\Concerns\ValidatesIdeaAttributes;
use App\Models\Idea;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreIdeaRequest extends FormRequest
{
    use ValidatesIdeaAttributes;

    public function authorize(): bool
    {
        return $this->user()->can('create', Idea::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->ideaAttributeRules($this->user()->currentWorkspace);
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectEmptyIdea($validator);
    }
}
