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
        $rules = RepurposeRules::rules($workspaceId);
        $rules['repurpose_id'] = ['required', 'string', 'uuid'];
        $rules['source_social_account_id'] = ['sometimes', ...array_slice($rules['source_social_account_id'], 1)];

        return $rules;
    }
}
