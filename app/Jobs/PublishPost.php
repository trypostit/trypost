<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\FinalizePostPublication;
use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishPost implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public Post $post) {}

    public function handle(): void
    {
        $this->post->markAsPublishing();

        foreach ($this->post->postPlatforms()->enabled()->get() as $postPlatform) {
            PublishToSocialPlatform::dispatch($postPlatform);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('PublishPost job failed', [
            'post_id' => $this->post->id,
            'error' => $exception?->getMessage(),
        ]);

        app(FinalizePostPublication::class)->handle($this->post);
    }
}
