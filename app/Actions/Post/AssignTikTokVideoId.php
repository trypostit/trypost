<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Analytics\SyncTryPostPublication;
use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Support\Facades\DB;

/**
 * Moves a TikTok post from its Content Posting `publish_id` to the public
 * video id TikTok reported for it, together with its analytics publication.
 * A post published before it had one gets it first, so the move still drops
 * an imported copy of the video and refuses a video another post holds.
 */
class AssignTikTokVideoId
{
    public function __construct(
        private readonly SyncTryPostPublication $syncPublication,
        private readonly UpsertAnalyticsPublication $publications,
    ) {}

    public function handle(Post $post, string $videoId): void
    {
        DB::transaction(function () use ($post, $videoId): void {
            $locked = Post::query()->lockForUpdate()->find($post->id);

            if (blank($locked)) {
                return;
            }

            $account = $locked->socialAccount;
            $previousUrl = $locked->platform_url;
            $videoUrl = filled($account) ? TikTokPublisher::postUrl($account, $videoId) : null;
            $publication = $locked->analyticsPublication()->first()
                ?? (filled($account) ? $this->syncPublication->handle($locked) : null);

            if (filled($publication)) {
                $this->publications->reconcileRemoteId($publication, $videoId);

                if (filled($videoUrl) && (blank($publication->permalink) || $publication->permalink === $previousUrl)) {
                    $publication->update(['permalink' => $videoUrl]);
                }
            }

            $locked->writePublication([
                'platform_post_id' => $videoId,
                'platform_url' => $videoUrl ?? $previousUrl,
            ]);
        });
    }
}
