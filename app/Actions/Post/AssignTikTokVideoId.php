<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Moves a TikTok post from its Content Posting `publish_id` to the public
 * video id TikTok reported for it, together with its analytics publication.
 */
class AssignTikTokVideoId
{
    public function __construct(private readonly UpsertAnalyticsPublication $publications) {}

    public function handle(Post $post, string $videoId): void
    {
        DB::transaction(function () use ($post, $videoId): void {
            $publication = $post->analyticsPublication()->first();

            if ($publication !== null) {
                $this->publications->reconcileRemoteId($publication, $videoId);
            }

            $post->writePublication([
                'platform_post_id' => $videoId,
                'platform_url' => $post->socialAccount
                    ? TikTokPublisher::postUrl($post->socialAccount, $videoId)
                    : $post->platform_url,
            ]);
        });
    }
}
