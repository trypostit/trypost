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
use Throwable;

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
        if (! $post->wasChanged('status')) {
            return;
        }

        $previousStatus = $this->previousStatus($post);

        DB::afterCommit(fn () => PostStatusChanged::dispatch($post, $previousStatus));
    }

    public function updated(Post $post): void
    {
        if (! $post->wasChanged('publish_status')
            || $post->publish_status !== PublishStatus::Published
            || ! filled($post->platform_post_id)) {
            return;
        }

        $this->dispatchAnalyticsSync($post);

        if (! PostHogService::isEnabled()) {
            return;
        }

        $accountId = $post->loadMissing('workspace')->workspace?->account_id;

        if (! $accountId) {
            return;
        }

        SyncAccountPublishingActivity::dispatch((string) $accountId)
            ->delay(now()->addSeconds(SyncAccountPublishingActivity::DEBOUNCE_SECONDS))
            ->afterCommit();
    }

    private function dispatchAnalyticsSync(Post $post): void
    {
        try {
            $account = $post->loadMissing('socialAccount')->socialAccount;

            if (! $account || ! app(FollowerCollectorFactory::class)->supports($post->platform)) {
                return;
            }

            $identity = TryPostPublicationIdentity::fromAccount(
                $account,
                app(ResolveAnalyticsAccountKey::class)->for($account),
            );

            SyncTryPostPublication::dispatch($identity, $post->id)->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function previousStatus(Post $post): ?PostStatus
    {
        $previous = $post->getRawOriginal('status');

        if ($previous instanceof PostStatus) {
            return $previous;
        }

        if (is_string($previous)) {
            return PostStatus::tryFrom($previous);
        }

        return null;
    }
}
