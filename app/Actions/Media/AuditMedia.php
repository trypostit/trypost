<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\StorageAttributes;

/**
 * Read-only consistency report over `medias` and the storage files.
 */
class AuditMedia
{
    public const int CHUNK = 500;

    public const string DIRECTORY = 'medias';

    /**
     * `$checkMissingFiles` false skips the one storage request per row (the
     * bucket listing behind `orphaned_files` stays: it is a few requests).
     *
     * @return array<string, list<array<string, string>>>
     */
    public static function execute(bool $checkMissingFiles = true): array
    {
        $report = [
            'orphaned_files' => [],
            'missing_files' => [],
            'owner_count' => [],
            'json_drift' => [],
            'workspace_mismatch' => [],
            'stale_uploads' => [],
        ];

        $report['orphaned_files'] = self::orphanedFiles();

        Media::query()->chunkById(self::CHUNK, function (Collection $rows) use (&$report, $checkMissingFiles): void {
            foreach ($rows as $media) {
                if ($checkMissingFiles && ! Storage::exists($media->path)) {
                    $report['missing_files'][] = ['media_id' => $media->id, 'path' => $media->path];
                }

                if ($media->ownerCount() !== 1) {
                    $report['owner_count'][] = [
                        'media_id' => $media->id,
                        'path' => $media->path,
                        'owners' => (string) $media->ownerCount(),
                    ];
                }
            }
        });

        $report['json_drift'] = [...self::jsonDrift(Post::class, 'post_id', 'post'), ...self::jsonDrift(Idea::class, 'idea_id', 'idea')];
        $report['workspace_mismatch'] = self::workspaceMismatches();
        $report['stale_uploads'] = self::staleUploads();

        return $report;
    }

    /** @return list<array<string, string>> */
    private static function orphanedFiles(): array
    {
        $orphans = [];
        $batch = [];

        $flush = function () use (&$batch, &$orphans): void {
            $known = Media::query()->whereIn('path', $batch)->pluck('path')->all();

            foreach (array_diff($batch, $known) as $path) {
                $orphans[] = ['path' => $path];
            }

            $batch = [];
        };

        foreach (Storage::listContents(self::DIRECTORY, deep: true) as $item) {
            /** @var StorageAttributes $item */
            if (! $item->isFile()) {
                continue;
            }

            $batch[] = $item->path();

            if (count($batch) >= self::CHUNK) {
                $flush();
            }
        }

        if ($batch !== []) {
            $flush();
        }

        return $orphans;
    }

    /**
     * @param  class-string<Model>  $owner
     * @return list<array<string, string>>
     */
    private static function jsonDrift(string $owner, string $column, string $type): array
    {
        $findings = [];

        $owner::query()->select(['id', 'media'])->chunkById(self::CHUNK, function (Collection $owners) use (&$findings, $column, $type): void {
            $owned = Media::query()
                ->whereIn($column, $owners->modelKeys())
                ->get(['id', $column])
                ->groupBy($column)
                ->map(fn (Collection $rows): array => $rows->pluck('id')->all());

            foreach ($owners as $model) {
                $ownedIds = $owned->get($model->id, []);
                $jsonIds = collect($model->media ?? [])->pluck('id')->filter()->unique()->values()->all();

                foreach (array_diff($jsonIds, $ownedIds) as $mediaId) {
                    $findings[] = ['owner_type' => $type, 'owner_id' => $model->id, 'media_id' => (string) $mediaId, 'problem' => 'json_without_row'];
                }

                foreach (array_diff($ownedIds, $jsonIds) as $mediaId) {
                    $findings[] = ['owner_type' => $type, 'owner_id' => $model->id, 'media_id' => (string) $mediaId, 'problem' => 'row_without_json'];
                }
            }
        });

        return $findings;
    }

    /** @return list<array<string, string>> */
    private static function workspaceMismatches(): array
    {
        $workspaceAlias = Relation::getMorphAlias(Workspace::class);

        $queries = [
            'post' => Media::query()
                ->join('posts', 'posts.id', '=', 'medias.post_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('posts.workspace_id', '!=', 'medias.workspace_id')
                    ->orWhereNull('medias.workspace_id'))
                ->select(['medias.id', 'medias.path', 'medias.workspace_id', 'posts.workspace_id as owner_workspace_id']),
            'idea' => Media::query()
                ->join('ideas', 'ideas.id', '=', 'medias.idea_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('ideas.workspace_id', '!=', 'medias.workspace_id')
                    ->orWhereNull('medias.workspace_id'))
                ->select(['medias.id', 'medias.path', 'medias.workspace_id', 'ideas.workspace_id as owner_workspace_id']),
            'feed_item' => Media::query()
                ->join('rss_feed_items', 'rss_feed_items.id', '=', 'medias.rss_feed_item_id')
                ->join('rss_feeds', 'rss_feeds.id', '=', 'rss_feed_items.rss_feed_id')
                ->where(fn (Builder $query) => $query
                    ->whereColumn('rss_feeds.workspace_id', '!=', 'medias.workspace_id')
                    ->orWhereNull('medias.workspace_id'))
                ->select(['medias.id', 'medias.path', 'medias.workspace_id', 'rss_feeds.workspace_id as owner_workspace_id']),
            'workspace' => Media::query()
                ->where('medias.mediable_type', $workspaceAlias)
                ->where(fn (Builder $query) => $query
                    ->whereColumn('medias.mediable_id', '!=', 'medias.workspace_id')
                    ->orWhereNull('medias.workspace_id'))
                ->select(['medias.id', 'medias.path', 'medias.workspace_id', 'medias.mediable_id as owner_workspace_id']),
        ];

        $findings = [];

        foreach ($queries as $owner => $query) {
            $query->chunkById(self::CHUNK, function (Collection $rows) use (&$findings, $owner): void {
                foreach ($rows as $row) {
                    $findings[] = [
                        'media_id' => $row->id,
                        'path' => $row->path,
                        'owner' => $owner,
                        'media_workspace_id' => (string) $row->workspace_id,
                        'owner_workspace_id' => (string) $row->getAttribute('owner_workspace_id'),
                    ];
                }
            }, 'medias.id', 'id');
        }

        return $findings;
    }

    /** @return list<array<string, string>> */
    private static function staleUploads(): array
    {
        $hours = (int) config('trypost.media.upload_retention_hours', 24);
        $findings = [];

        Media::query()
            ->temporaryUploads()
            ->where('created_at', '<', now()->subHours($hours))
            ->chunkById(self::CHUNK, function (Collection $rows) use (&$findings): void {
                foreach ($rows as $media) {
                    $findings[] = [
                        'media_id' => $media->id,
                        'path' => $media->path,
                        'created_at' => $media->created_at->toIso8601String(),
                    ];
                }
            });

        return $findings;
    }
}
