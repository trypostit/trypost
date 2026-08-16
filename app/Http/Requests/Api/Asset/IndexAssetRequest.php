<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Asset;

use App\Enums\Media\Type as MediaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public static function filterRules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', Rule::enum(MediaType::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::filterRules();
    }
}
