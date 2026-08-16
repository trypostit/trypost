<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Support\PostMediaRules;
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
            'asset_id' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ];
    }
}
