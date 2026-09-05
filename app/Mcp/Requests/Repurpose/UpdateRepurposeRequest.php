<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Repurpose;

use App\Support\Repurpose\RepurposeRules;

class UpdateRepurposeRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?string $workspaceId = null): array
    {
        return [
            'repurpose_id' => ['required', 'string'],
            ...RepurposeRules::rules($workspaceId),
            'source_social_account_id' => ['sometimes', 'string', 'uuid'],
        ];
    }
}
