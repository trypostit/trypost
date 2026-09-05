<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Repurpose;

class ListRepurposeItemsRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'repurpose_id' => ['required', 'string', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
