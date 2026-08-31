<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostPlatform\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Models\PostPlatform;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfileApi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileGoogleBusinessProfilePost implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public PostPlatform $postPlatform)
    {
        $this->onQueue($postPlatform->platform->queue());
    }

    public function handle(GoogleBusinessProfileApi $api, ConnectionVerifier $verifier): void
    {
        $this->postPlatform->refresh()->load(['socialAccount', 'post.postPlatforms']);

        if (! in_array($this->postPlatform->status, [Status::Submitted, Status::PendingReview], true)
            || blank($this->postPlatform->platform_post_id)) {
            return;
        }

        $account = $this->postPlatform->socialAccount;
        if ($account->needsProactiveTokenRefresh()) {
            $verifier->refreshToken($account);
        }

        $remote = $api->localPost($account, $this->postPlatform->platform_post_id);
        $state = (string) data_get($remote, 'state', 'PROCESSING');

        match (true) {
            in_array($state, ['LIVE', 'RECURRING'], true) => $this->postPlatform->markAsPublished(
                $this->postPlatform->platform_post_id,
                data_get($remote, 'searchUrl', $this->postPlatform->platform_url),
            ),
            $state === 'REJECTED' => $this->postPlatform->markAsRejected(
                'Google rejected this post during review.',
                ['provider_state' => $state, 'remote' => $this->safeRemoteContext($remote)],
            ),
            default => $this->postPlatform->update([
                'status' => $state === 'PROCESSING' ? Status::PendingReview : Status::Submitted,
                'platform_url' => data_get($remote, 'searchUrl', $this->postPlatform->platform_url),
                'last_reconciled_at' => now(),
                'error_context' => ['provider_state' => $state],
            ]),
        };

        if (in_array($state, ['LIVE', 'RECURRING'], true)) {
            $this->postPlatform->update(['last_reconciled_at' => now()]);
        }

        $this->reconcilePostStatus();
        PostPlatformStatusUpdated::dispatch($this->postPlatform->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $this->postPlatform->refresh();

        if (! in_array($this->postPlatform->status, [Status::Submitted, Status::PendingReview], true)) {
            return;
        }

        $this->postPlatform->update([
            'status' => Status::Failed,
            'error_message' => 'Google Business Profile post status could not be confirmed after several attempts.',
            'error_context' => [
                'category' => 'reconciliation_failed',
                'failed_at' => now()->toIso8601String(),
            ],
            'last_reconciled_at' => now(),
        ]);

        $this->reconcilePostStatus();
        PostPlatformStatusUpdated::dispatch($this->postPlatform->fresh());

        if ($exception) {
            report($exception);
        }
    }

    /** @param array<string, mixed> $remote
     * @return array<string, mixed>
     */
    private function safeRemoteContext(array $remote): array
    {
        return array_filter([
            'name' => data_get($remote, 'name'),
            'state' => data_get($remote, 'state'),
            'topic_type' => data_get($remote, 'topicType'),
            'update_time' => data_get($remote, 'updateTime'),
        ]);
    }

    private function reconcilePostStatus(): void
    {
        $post = $this->postPlatform->post->fresh('postPlatforms');
        $enabled = $post->postPlatforms->where('enabled', true);
        $published = $enabled->where('status', Status::Published)->count();
        $failed = $enabled->whereIn('status', [Status::Failed, Status::Rejected])->count();

        if (($published + $failed) < $enabled->count()) {
            return;
        }

        match (true) {
            $published === $enabled->count() => $post->markAsPublished(),
            $published > 0 => $post->markAsPartiallyPublished(),
            default => $post->markAsFailed(),
        };
    }
}
