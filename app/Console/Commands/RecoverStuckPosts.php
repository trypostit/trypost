<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Events\PostStatusUpdated;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Models\Post;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Console\Command;

class RecoverStuckPosts extends Command
{
    protected $signature = 'social:recover-stuck-posts';

    protected $description = 'Recover posts stuck in publishing status for more than 1 hour';

    private const array IN_FLIGHT = [PublishStatus::Publishing, PublishStatus::Pending, PublishStatus::Retrying];

    public function __construct(
        private readonly TikTokPhotoDerivativeCleaner $tiktokPhotoDerivativeCleaner,
        private readonly GoogleBusinessDerivativeCleaner $googleBusinessDerivativeCleaner,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        Post::query()
            ->where('status', PostStatus::Publishing)
            ->where('updated_at', '<=', now()->subHour())
            ->each(function (Post $post): void {
                $stale = in_array($post->publish_status, self::IN_FLIGHT, true)
                    && $post->retry_at === null
                    && ($post->publication_updated_at === null || $post->publication_updated_at->lessThanOrEqualTo(now()->subHour()));

                if ($stale) {
                    $this->tiktokPhotoDerivativeCleaner->cleanupUnlessPublishInFlight($post->error_context, $post->id);
                    $this->googleBusinessDerivativeCleaner->cleanup($post);

                    $post->writePublication([
                        'publish_status' => PublishStatus::Failed,
                        'error_message' => __('posts.errors.publishing_timed_out'),
                        'error_context' => [
                            ...($post->error_context ?? []),
                            'category' => ErrorCategory::Timeout->value,
                            'failed_at' => now()->toIso8601String(),
                        ],
                    ]);
                }

                $this->failExpiredReview($post);

                // A delayed platform-unavailable retry keeps the post Retrying
                // with a fresh clock: do not finalize while that work is live.
                if (in_array($post->publish_status, [...self::IN_FLIGHT, PublishStatus::PendingReview], true)) {
                    return;
                }

                app(FinalizePostPublication::class)->handle($post);

                if ($stale) {
                    PostStatusUpdated::dispatch($post->fresh());
                }
            });
    }

    /**
     * PendingReview is supposed to last up to Google's review ceiling, timed
     * from submitted_at only. A scheduled draft can be days old before it
     * enters review, so created_at must not trip the ceiling. A post without
     * submitted_at stays in review until reconcile or a later recover after
     * markPublicationPendingReview writes the clock.
     */
    private function failExpiredReview(Post $post): void
    {
        $cutoff = now()->subHours(ReconcileGoogleBusinessPost::REVIEW_CEILING_HOURS);

        if ($post->publish_status !== PublishStatus::PendingReview || $post->submitted_at === null || $post->submitted_at->greaterThan($cutoff)) {
            return;
        }

        $post->markPublicationRejected(
            (string) $post->platform_post_id,
            $post->platform_url,
            __('posts.errors.review_unconfirmed'),
            [
                ...($post->error_context ?? []),
                'category' => 'review_unconfirmed',
                'failed_at' => now()->toIso8601String(),
            ],
        );
        $this->googleBusinessDerivativeCleaner->cleanup($post);
        PostStatusUpdated::dispatch($post->fresh());
    }
}
