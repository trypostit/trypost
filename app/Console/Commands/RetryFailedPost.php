<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Support\Social\GoogleBusinessProfileMediaDerivativeCleaner;
use App\Support\Social\PublishCheckpoint;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class RetryFailedPost extends Command
{
    protected $signature = 'posts:retry
        {post : ID of the post whose failed platforms should be retried}';

    protected $description = 'Retry failed platforms, resuming in-flight remote publishes when a checkpoint exists';

    public function __construct(
        private readonly TikTokPhotoDerivativeCleaner $tiktokPhotoDerivativeCleaner,
        private readonly GoogleBusinessProfileMediaDerivativeCleaner $googleBusinessProfileMediaDerivativeCleaner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $post = Post::query()->find((string) $this->argument('post'));

        if (! $post) {
            $this->error('Post not found.');

            return self::FAILURE;
        }

        if (! $this->isRetryable($post)) {
            $this->error('Only failed or partially published posts can be retried.');

            return self::FAILURE;
        }

        $failedPlatforms = $this->failedPlatforms($post);

        if ($failedPlatforms->isEmpty()) {
            $this->warn('No failed enabled platforms matched this post.');

            return self::FAILURE;
        }

        $this->table(
            ['Post platform ID', 'Platform', 'Account', 'Last error', 'Mode'],
            $failedPlatforms->map(fn (PostPlatform $postPlatform): array => [
                $postPlatform->id,
                $postPlatform->platform->value,
                $postPlatform->display_username ?? '—',
                $postPlatform->error_message ?? '—',
                $this->resumableContext($postPlatform->error_context) === null ? 'New' : 'Resume',
            ])->all(),
        );

        if (! $this->confirm('Queue publish attempts for these failed platforms?')) {
            $this->info('Retry cancelled.');

            return self::SUCCESS;
        }

        $retryEntries = $this->prepareRetryEntries($post);

        if ($retryEntries === []) {
            $this->warn('The post changed while the command was running; nothing was retried.');

            return self::FAILURE;
        }

        foreach ($retryEntries as $entry) {
            if ($entry['platform'] === SocialPlatform::TikTok && PublishCheckpoint::tiktokPublishId($entry['error_context']) === null) {
                $this->tiktokPhotoDerivativeCleaner->cleanup($entry['original_error_context'], $entry['id']);
            }

            if ($entry['platform'] === SocialPlatform::GoogleBusinessProfile
                && PublishCheckpoint::googleBusinessProfileDerivativePath($entry['error_context']) === null) {
                $this->googleBusinessProfileMediaDerivativeCleaner->cleanup($entry['original_error_context'], $entry['id']);
            }

            $postPlatform = PostPlatform::query()->findOrFail($entry['id']);
            PublishToSocialPlatform::dispatch($postPlatform);
        }

        Log::info('Failed post platforms queued for manual retry', [
            'post_id' => $post->id,
            'post_platform_ids' => array_column($retryEntries, 'id'),
        ]);

        $this->info(count($retryEntries).' publish attempt(s) queued.');

        return self::SUCCESS;
    }

    private function isRetryable(Post $post): bool
    {
        return in_array($post->status, [PostStatus::Failed, PostStatus::PartiallyPublished], true);
    }

    /**
     * @return Collection<int, PostPlatform>
     */
    private function failedPlatforms(Post $post, bool $lockForUpdate = false): Collection
    {
        return PostPlatform::query()
            ->with('socialAccount')
            ->where('post_id', $post->id)
            ->enabled()
            ->where('status', PlatformStatus::Failed)
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->get();
    }

    /**
     * @return list<array{
     *     id: string,
     *     platform: SocialPlatform,
     *     error_context: array<string, mixed>|null,
     *     original_error_context: array<string, mixed>|null
     * }>
     */
    private function prepareRetryEntries(Post $post): array
    {
        return DB::transaction(function () use ($post): array {
            $lockedPost = Post::query()->lockForUpdate()->find($post->id);

            if (! $lockedPost || ! $this->isRetryable($lockedPost)) {
                return [];
            }

            $platforms = $this->failedPlatforms($lockedPost, lockForUpdate: true);

            if ($platforms->isEmpty()) {
                return [];
            }

            $entries = [];

            foreach ($platforms as $postPlatform) {
                $nextContext = $this->resumableContext($postPlatform->error_context);
                $entries[] = [
                    'id' => $postPlatform->id,
                    'platform' => $postPlatform->platform,
                    'error_context' => $nextContext,
                    'original_error_context' => $postPlatform->error_context,
                ];

                $postPlatform->update([
                    'status' => PlatformStatus::Pending,
                    'platform_post_id' => null,
                    'platform_url' => null,
                    'error_message' => null,
                    'error_context' => $nextContext,
                    'published_at' => null,
                ]);
            }
            $lockedPost->update(['status' => PostStatus::Publishing]);

            return $entries;
        });
    }

    /**
     * Keep in-flight checkpoints only. Confirmed remote failures must start over.
     *
     * @param  array<string, mixed>|null  $context
     * @return array<string, mixed>|null
     */
    private function resumableContext(?array $context): ?array
    {
        if (ErrorCategory::tryFromContext($context)?->isResumable() !== true) {
            return null;
        }

        $kept = [];
        $publishId = PublishCheckpoint::tiktokPublishId($context);
        $workflow = PublishCheckpoint::instagramWorkflow($context);
        $googleDerivativePath = PublishCheckpoint::googleBusinessProfileDerivativePath($context);

        if ($publishId !== null) {
            $kept[PublishCheckpoint::TIKTOK_PUBLISH_ID] = $publishId;
            $paths = PublishCheckpoint::tiktokDerivativePaths($context);

            if ($paths !== []) {
                $kept[PublishCheckpoint::TIKTOK_DERIVATIVE_PATHS] = $paths;
            }
        }

        if ($workflow !== null) {
            $kept[PublishCheckpoint::INSTAGRAM_WORKFLOW] = $workflow;
        }

        if ($this->googleBusinessProfileMediaDerivativeCleaner->isManagedDerivativePath($googleDerivativePath)
            && Storage::exists($googleDerivativePath)) {
            $kept[PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH] = $googleDerivativePath;
        }

        return $kept === [] ? null : $kept;
    }
}
