<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class ListWorkspaceAssets
{
    /**
     * @return MorphMany<Media, Workspace>
     */
    public static function execute(Workspace $workspace, ?string $search = null, ?string $type = null): MorphMany
    {
        return $workspace->getMedia('assets')
            ->when(filled($search), fn ($query) => $query->where('original_filename', 'ilike', '%'.trim($search).'%'))
            ->when(filled($type), fn ($query) => $query->where('type', $type))
            ->latest();
    }
}
