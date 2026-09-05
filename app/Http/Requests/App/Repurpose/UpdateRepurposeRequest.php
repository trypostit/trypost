<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Repurpose;

use App\Support\Repurpose\RepurposeRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('repurpose'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = RepurposeRules::rules($this->user()->current_workspace_id);
        $rules['source_social_account_id'] = ['sometimes', ...array_slice($rules['source_social_account_id'], 1)];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return RepurposeRules::messages();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return RepurposeRules::attributes();
    }
}
