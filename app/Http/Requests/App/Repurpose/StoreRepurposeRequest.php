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
     * Creation only asks for the source account; destinations are chosen on the
     * edit screen, where there is room for each network's options.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source_social_account_id' => RepurposeRules::rules()['source_social_account_id'],
            'template' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
