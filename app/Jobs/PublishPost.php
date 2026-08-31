<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public Post $post) {}

    public function uniqueId(): string
    {
        return $this->post->id;
    }

    public function handle(): void
    {
        $targets = DB::transaction(function () {
            $post = Post::query()->lockForUpdate()->findOrFail($this->post->id);
            if (! in_array($post->status, [PostStatus::Scheduled, PostStatus::Publishing], true)) {
                return collect();
            }

            $enabledTargets = $post->postPlatforms()->enabled()->lockForUpdate()->get();
            if ($enabledTargets->isEmpty()) {
                $post->markAsFailed();
                Log::warning('PublishPost stopped because the post has no enabled targets', ['post_id' => $post->id]);
            }

            $targets = $enabledTargets->where('status', PostPlatformStatus::Pending);
            if ($targets->isNotEmpty()) {
                $post->markAsPublishing();
            }

            $this->post = $post;

            return $targets;
        });

        if ($targets->isEmpty()) {
            return;
        }

        foreach ($targets as $postPlatform) {
            PublishToSocialPlatform::dispatch($postPlatform);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('PublishPost job failed', [
            'post_id' => $this->post->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->post->markAsFailed();
    }
}
