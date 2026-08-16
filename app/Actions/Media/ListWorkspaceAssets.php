<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class ListWorkspaceAssets
{
    public static function execute(Workspace $workspace, ?string $search = null, ?string $type = null): LengthAwarePaginator
    {
        return self::query($workspace, $search, $type)
            ->paginate((int) config('app.pagination.default'));
    }

    /**
     * @return Builder<Media>
     */
    public static function query(Workspace $workspace, ?string $search = null, ?string $type = null): Builder
    {
        return Media::query()
            ->where('mediable_type', Relation::getMorphAlias(Workspace::class))
            ->where('mediable_id', $workspace->id)
            ->where('collection', 'assets')
            ->when(filled($search), fn (Builder $query) => $query->where('original_filename', 'ilike', '%'.trim($search).'%'))
            ->when(filled($type), fn (Builder $query) => $query->where('type', $type))
            ->latest()
            ->orderByDesc('id');
    }
}
