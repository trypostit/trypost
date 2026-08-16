<?php

declare(strict_types=1);

namespace App\Http\Requests\Mcp\Asset;

use App\Http\Requests\Api\Asset\IndexAssetRequest;
use Illuminate\Foundation\Http\FormRequest;

class ListAssetsRequest extends FormRequest
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
            ...IndexAssetRequest::filterRules(),
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
