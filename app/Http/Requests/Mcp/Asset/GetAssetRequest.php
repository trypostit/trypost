<?php

declare(strict_types=1);

namespace App\Http\Requests\Mcp\Asset;

use Illuminate\Foundation\Http\FormRequest;

class GetAssetRequest extends FormRequest
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
            'asset_id' => ['required', 'uuid'],
        ];
    }
}
