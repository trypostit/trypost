<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Http\Requests\App\Idea\Concerns\ValidatesIdeaAttributes;
use App\Models\Idea;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateIdeaRequest extends FormRequest
{
    use ValidatesIdeaAttributes;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('idea'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Idea $idea */
        $idea = $this->route('idea');

        return $this->ideaAttributeRules(
            $this->user()->currentWorkspace,
            collect($idea->media ?? [])->pluck('id')->filter()->values()->all(),
        );
    }

    public function withValidator(Validator $validator): void
    {
        $this->rejectEmptyIdea($validator, $this->route('idea'));
    }
}
