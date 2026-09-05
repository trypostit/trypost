<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Repurpose;

use App\Support\Repurpose\RepurposeRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return RepurposeRules::rules($this->user()->currentWorkspace?->id);
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
