<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Repurpose;

class RepurposeIdRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'repurpose_id' => ['required', 'string', 'uuid'],
        ];
    }
}
