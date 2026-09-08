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
            'repurpose_id' => ['required', 'string', 'uuid'],
            ...RepurposeRules::settings($workspaceId, sourceRequired: false),
            ...RepurposeRules::destinations($workspaceId),
        ];
    }
}
