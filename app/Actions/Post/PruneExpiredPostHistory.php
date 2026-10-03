<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\Post\Status;
use App\Models\Post;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PruneExpiredPostHistory
{
    public const int CHUNK = 200;

    public static function execute(CarbonInterface $cutoff, bool $dryRun = false): int
    {
        $query = Post::query()
            ->whereIn('status', [Status::Published, Status::PartiallyPublished])
            ->whereNotNull('published_at')
            ->where('published_at', '<', $cutoff);

        if ($dryRun) {
            return $query->count();
        }

        $pruned = 0;

        $query->select('id')->chunkById(self::CHUNK, function (Collection $posts) use (&$pruned): void {
            $ids = $posts->modelKeys();

            DB::transaction(function () use ($ids): void {
                DeleteOwnedMedia::forPosts($ids);

                Post::query()->whereKey($ids)->delete();
            });

            $pruned += count($ids);
        });

        return $pruned;
    }
}
