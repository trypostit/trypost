<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;

class FindWorkspaceAsset
{
    public static function execute(Workspace $workspace, string $assetId): ?Media
    {
        return $workspace->getMedia('assets')->whereKey($assetId)->first();
    }
}
