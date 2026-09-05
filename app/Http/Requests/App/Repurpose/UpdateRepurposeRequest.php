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
        return [
            ...RepurposeRules::rules($this->user()->current_workspace_id),
            'source_social_account_id' => ['sometimes', 'string', 'uuid'],
        ];
    }
}
