<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Repurpose;

use App\Support\Repurpose\RepurposeRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRepurposeRequest extends FormRequest
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
        return [
            ...RepurposeRules::rules(),
            'source_social_account_id' => ['sometimes', 'string', 'uuid'],
        ];
    }
}
