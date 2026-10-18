<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Actions\SavedReply\ListSavedReplies;
use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;
use App\Support\Inertia\LazilyKeyedOnceProp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class InboxSavedRepliesResource
{
    /**
     * The workspace's saved replies for the inbox pickers, sent once and again
     * only when one of them is created, changed or deleted.
     */
    public static function once(Workspace $workspace): LazilyKeyedOnceProp
    {
        return new LazilyKeyedOnceProp(
            fn (): array => self::make($workspace),
            fn (): string => self::key($workspace),
        );
    }

    /**
     * @return list<array{id: string, name: string, content: string}>
     */
    public static function make(Workspace $workspace): array
    {
        return ListSavedReplies::execute($workspace)
            ->get(['id', 'name', 'content', 'created_at'])
            ->map(fn (WorkspaceSavedReply $savedReply): array => $savedReply->only(['id', 'name', 'content']))
            ->values()
            ->all();
    }

    public static function key(Workspace $workspace): string
    {
        $aggregates = Workspace::query()
            ->whereKey($workspace->id)
            ->select('workspaces.id')
            ->withCount(['savedReplies' => fn (Builder $query): Builder => $query->withTrashed()])
            ->withMax(['savedReplies' => fn (Builder $query): Builder => $query->withTrashed()], 'updated_at')
            ->toBase()
            ->first();

        $version = hash('xxh128', json_encode([
            data_get($aggregates, 'saved_replies_count'),
            data_get($aggregates, 'saved_replies_max_updated_at'),
            Cache::get(self::versionKey($workspace->id), 0),
        ]));

        return "saved-replies:{$workspace->id}:{$version}";
    }

    public static function bumpVersion(string $workspaceId): void
    {
        $key = self::versionKey($workspaceId);

        Cache::add($key, 0);
        Cache::increment($key);
    }

    private static function versionKey(string $workspaceId): string
    {
        return "saved-replies:version:{$workspaceId}";
    }
}
