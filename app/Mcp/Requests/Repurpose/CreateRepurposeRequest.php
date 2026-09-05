<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Repurpose;

use App\Support\Repurpose\RepurposeRules;

class CreateRepurposeRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(?string $workspaceId = null): array
    {
        return RepurposeRules::rules($workspaceId);
    }
}
