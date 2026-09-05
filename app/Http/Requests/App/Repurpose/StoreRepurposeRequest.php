<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Repurpose;

use App\Models\Repurpose;
use App\Support\Repurpose\RepurposeRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreRepurposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Repurpose::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = RepurposeRules::rules($this->user()->current_workspace_id);

        return [
            'source_social_account_id' => $rules['source_social_account_id'],
            'source_format' => $rules['source_format'],
            'template' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
