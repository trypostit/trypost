<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\PostPlatform\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Models\PostPlatform;
use App\Services\Post\PostPublicationFinalizer;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\GoogleBusinessProfile\GoogleBusinessProfileApi;
use App\Support\Social\GoogleBusinessProfileMediaDerivativeCleaner;
use App\Support\Social\PublishCheckpoint;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReconcileGoogleBusinessProfilePost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 7200;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 1800];

    public function __construct(public PostPlatform $postPlatform)
    {
        $this->onQueue($postPlatform->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->postPlatform->id;
    }

    public function handle(GoogleBusinessProfileApi $api, ConnectionVerifier $verifier): void
    {
        $this->postPlatform->refresh()->load(['socialAccount', 'post.postPlatforms']);

        if (! in_array($this->postPlatform->status, [Status::Submitted, Status::PendingReview], true)
            || blank($this->postPlatform->platform_post_id)) {
            return;
        }

        $account = $this->postPlatform->socialAccount;
        $derivativePath = PublishCheckpoint::googleBusinessProfileDerivativePath($this->postPlatform->error_context);
        if ($account->needsProactiveTokenRefresh()) {
            $verifier->refreshToken($account);
        }

        $remote = $api->localPost($account, $this->postPlatform->platform_post_id);
        $state = (string) data_get($remote, 'state', 'PROCESSING');

        if (in_array($state, ['LIVE', 'RECURRING'], true)) {
            $this->postPlatform->markAsPublished(
                $this->postPlatform->platform_post_id,
                data_get($remote, 'searchUrl', $this->postPlatform->platform_url),
            );
            app(GoogleBusinessProfileMediaDerivativeCleaner::class)->cleanupPath($derivativePath, $this->postPlatform->id);
        } elseif ($state === 'REJECTED') {
            $this->postPlatform->markAsRejected(
                'Google rejected this post during review.',
                ['provider_state' => $state, 'remote' => $this->safeRemoteContext($remote)],
            );
            app(GoogleBusinessProfileMediaDerivativeCleaner::class)->cleanupPath($derivativePath, $this->postPlatform->id);
        } else {
            $this->postPlatform->update([
                'status' => $state === 'PROCESSING' ? Status::PendingReview : Status::Submitted,
                'platform_url' => data_get($remote, 'searchUrl', $this->postPlatform->platform_url),
                'last_reconciled_at' => now(),
                'error_context' => array_filter([
                    'provider_state' => $state,
                    PublishCheckpoint::GOOGLE_BUSINESS_PROFILE_DERIVATIVE_PATH => $derivativePath,
                ], fn (mixed $value): bool => $value !== null && $value !== ''),
            ]);
        }

        if (in_array($state, ['LIVE', 'RECURRING'], true)) {
            $this->postPlatform->update(['last_reconciled_at' => now()]);
        }

        app(PostPublicationFinalizer::class)->finalize($this->postPlatform);
        PostPlatformStatusUpdated::dispatch($this->postPlatform->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $this->postPlatform->refresh();

        if (! in_array($this->postPlatform->status, [Status::Submitted, Status::PendingReview], true)) {
            return;
        }

        $derivativePath = PublishCheckpoint::googleBusinessProfileDerivativePath($this->postPlatform->error_context);

        $this->postPlatform->update([
            'status' => Status::Failed,
            'error_message' => 'Google Business Profile post status could not be confirmed after several attempts.',
            'error_context' => [
                'category' => 'reconciliation_failed',
                'failed_at' => now()->toIso8601String(),
            ],
            'last_reconciled_at' => now(),
        ]);

        app(GoogleBusinessProfileMediaDerivativeCleaner::class)->cleanupPath($derivativePath, $this->postPlatform->id);

        app(PostPublicationFinalizer::class)->finalize($this->postPlatform);
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
}
