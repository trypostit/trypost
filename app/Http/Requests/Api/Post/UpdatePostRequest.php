<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Support\Requests\Post\PostRequestRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return PostRequestRules::update($this->user()->currentWorkspace, $this->route('post'), $this->all());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PostRequestRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PostRequestRules::attributes();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => PostRequestRules::afterUpdate($validator, $this->route('post'), $this->all()));
    }
}
