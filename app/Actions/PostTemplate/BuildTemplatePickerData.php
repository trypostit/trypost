<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Dto\LibraryTemplate;
use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TemplateLibrary;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BuildTemplatePickerData
{
    /**
     * @return array{library: list<LibraryTemplate>, team: Collection<int, PostTemplate>, personal: Collection<int, PostTemplate>, has_more: array{team: bool, personal: bool}}
     */
    public static function execute(Workspace $workspace, User $user, ?string $search): array
    {
        $cap = (int) config('app.pagination.default');

        $team = self::scoped(PostTemplate::query()->where('workspace_id', $workspace->id)->team(), $search, $cap);
        $personal = self::scoped(PostTemplate::query()->where('workspace_id', $workspace->id)->personalFor($user), $search, $cap);

        return [
            'library' => TemplateLibrary::filter(['search' => $search]),
            'team' => $team->take($cap)->values(),
            'personal' => $personal->take($cap)->values(),
            'has_more' => [
                'team' => $team->count() > $cap,
                'personal' => $personal->count() > $cap,
            ],
        ];
    }

    /**
     * @param  Builder<PostTemplate>  $query
     * @return Collection<int, PostTemplate>
     */
    private static function scoped(Builder $query, ?string $search, int $cap): Collection
    {
        return $query
            ->with('user:id,name')
            ->search($search)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($cap + 1)
            ->get();
    }
}
