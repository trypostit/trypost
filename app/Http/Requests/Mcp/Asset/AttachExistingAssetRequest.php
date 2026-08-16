<?php

declare(strict_types=1);

namespace App\Http\Requests\Mcp\Asset;

use App\Http\Requests\Api\Post\AttachExistingAssetRequest as ApiAttachExistingAssetRequest;
use Illuminate\Foundation\Http\FormRequest;

class AttachExistingAssetRequest extends FormRequest
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
            'post_id' => ['required', 'uuid'],
            ...(new ApiAttachExistingAssetRequest)->rules(),
        ];
    }
}
