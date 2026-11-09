<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\Webhook\EventType;
use App\Events\PostCreated;
use App\Events\PostNetworkPresenceChanged;
use App\Events\PostStatusChanged;
use App\Events\PostStatusUpdated;
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

    /**
     * The network side of the post moved: the network deleted it or listed it
     * again, or it just went live.
     */
    public function updated(Post $post): void
    {
        if (! $post->wasChanged('publish_status')) {
            return;
        }

        $networkEvent = EventType::fromPublishStatus($post->publish_status, $post->getOriginal('publish_status'));

        if (filled($networkEvent)) {
            $this->announceNetworkPresence($post, $networkEvent);

            return;
        }

        if ($post->publish_status === PublishStatus::Published && filled($post->platform_post_id)) {
            $this->syncPublicationAnalytics($post);
            $this->syncPublishingActivity($post);
        }
    }

    private function announceNetworkPresence(Post $post, EventType $event): void
    {
        DB::afterCommit(function () use ($post, $event): void {
            PostNetworkPresenceChanged::dispatch($post, $event);
            PostStatusUpdated::dispatch($post);
        });
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
