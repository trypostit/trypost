<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Actions\Idea\CreateIdea;
use App\Models\Idea;
use App\Models\RssFeedItem;
use App\Models\User;

class SaveRssFeedItemAsIdea
{
    public static function execute(RssFeedItem $item, User $user): Idea
    {
        $media = ImportRssFeedItemImage::execute($item);

        return CreateIdea::execute($item->feed->workspace, $user, [
            'title' => mb_substr($item->title, 0, 255),
            'body' => collect([$item->excerpt, $item->url])->filter(fn (?string $part): bool => filled($part))->implode("\n\n"),
            'idea_stage_id' => null,
            'media_ids' => $media === null ? [] : [$media->id],
        ]);
    }
}
