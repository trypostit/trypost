<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Events\PostCreated;
use App\Events\PostStatusChanged;
use App\Jobs\Analytics\SyncTryPostPublication;
use App\Jobs\PostHog\SyncAccountPublishingActivity;
use App\Models\Post;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\PostHogService;
use Illuminate\Support\Facades\DB;

class PostObserver
{
    public function created(Post $post): void
    {
        DB::afterCommit(fn () => PostCreated::dispatch($post));
    }

    public function saving(Post $post): void
    {
        if ($post->isDirty('status') && $post->status === PostStatus::Draft) {
            $post->fill(Post::withoutRecurrence());
        }
    }

    public function saved(Post $post): void
    {
        if ($post->wasChanged('status')) {
            $previousStatus = $post->getOriginal('status');

            DB::afterCommit(fn () => PostStatusChanged::dispatch($post, $previousStatus));
        }
    }

    public function updated(Post $post): void
    {
        if (! $this->wasJustPublished($post)) {
            return;
        }

        $this->syncPublicationAnalytics($post);
        $this->syncPublishingActivity($post);
    }

    private function wasJustPublished(Post $post): bool
    {
        return $post->wasChanged('publish_status')
            && $post->publish_status === PublishStatus::Published
            && filled($post->platform_post_id);
    }

    /**
     * Never fails the publication: an error here is only reported.
     */
    private function syncPublicationAnalytics(Post $post): void
    {
        rescue(function () use ($post): void {
            $account = $post->loadMissing('socialAccount')->socialAccount;

            if (blank($account) || ! app(FollowerCollectorFactory::class)->supports($post->platform)) {
                return;
            }

            SyncTryPostPublication::dispatch(
                TryPostPublicationIdentity::fromAccount($account, app(ResolveAnalyticsAccountKey::class)->for($account)),
                $post->id,
            )->afterCommit();
        });
    }

    private function syncPublishingActivity(Post $post): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $accountId = $post->loadMissing('workspace')->workspace?->account_id;

        if (blank($accountId)) {
            return;
        }

        SyncAccountPublishingActivity::dispatch((string) $accountId)
            ->delay(now()->addSeconds(SyncAccountPublishingActivity::DEBOUNCE_SECONDS))
            ->afterCommit();
    }
}
