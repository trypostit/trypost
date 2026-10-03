<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Actions\Media\DeleteOwnedMedia;
use App\Models\RssFeed;
use Illuminate\Support\Facades\DB;

class DeleteRssFeed
{
    /**
     * Locks the feed's items before their media rows, the same order the
     * image import takes, so a concurrent import cannot slip in between.
     */
    public static function execute(RssFeed $feed): void
    {
        DB::transaction(function () use ($feed): void {
            DeleteOwnedMedia::forFeedItems($feed->items()->orderBy('id')->lockForUpdate()->pluck('id')->all());
            $feed->delete();
        });
    }
}
