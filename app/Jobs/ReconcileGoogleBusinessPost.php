<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\GoogleBusiness\LocalPostState;
use App\Enums\PostPlatform\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\PostPlatform;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\GoogleBusinessPublisher;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Settles a Google Business Profile target that the publish job had to leave
 * open. Google answers a create with 200 long before the post clears review,
 * so the state it reports later is what decides published vs. rejected.
 */
class ReconcileGoogleBusinessPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Must exceed HasSocialHttpClient's 120s HTTP timeout so a slow GET cannot kill the worker. */
    public int $timeout = 180;

    public int $uniqueFor = 600;

    /**
     * How long a target may sit in review before we stop believing it will
     * settle. Generous on purpose: a short ceiling fails posts that were only
     * slow, and Google normally clears review in minutes.
     */
    public const REVIEW_CEILING_HOURS = 24;

    public function __construct(public PostPlatform $postPlatform)
    {
        $this->onQueue($postPlatform->platform->queue());
    }

    public function uniqueId(): string
    {
        return $this->postPlatform->id;
    }

    public function handle(): void
    {
        $this->postPlatform->refresh();

        if ($this->postPlatform->status !== Status::PendingReview || blank($this->postPlatform->platform_post_id)) {
            return;
        }

        $account = $this->postPlatform->socialAccount;

        try {
            if ($account->needsProactiveTokenRefresh()) {
                app(ConnectionVerifier::class)->refreshToken($account);
            }

            $remote = app(GoogleBusinessPublisher::class)->fetchLocalPost($account, (string) $this->postPlatform->platform_post_id);
        } catch (TokenExpiredException|PlatformUnavailableException|ConnectionException $e) {
            $this->deferOrGiveUp($e->getMessage());

            return;
        } catch (GoogleBusinessPublishException $e) {
            if (in_array($e->category, [ErrorCategory::ServerError, ErrorCategory::RateLimit], true)) {
                $this->deferOrGiveUp($e->userMessage);

                return;
            }

            $this->giveUp($e->userMessage, [
                'category' => $e->category->value,
                'platform_error_code' => $e->platformErrorCode,
            ]);

            return;
        }

        $state = LocalPostState::fromApi(data_get($remote, 'state'));
        $platformUrl = (string) (data_get($remote, 'searchUrl') ?: $this->postPlatform->platform_url);

        if ($state->isLive()) {
            $this->postPlatform->markAsPublished((string) $this->postPlatform->platform_post_id, $platformUrl);
        } elseif ($state->isRejected()) {
            $this->postPlatform->markAsRejected(
                (string) $this->postPlatform->platform_post_id,
                $platformUrl,
                __('posts.errors.rejected_in_review'),
                ['provider_state' => $state->value],
            );
        } elseif ($this->reviewExpired()) {
            $this->postPlatform->markAsRejected(
                (string) $this->postPlatform->platform_post_id,
                $platformUrl,
                __('posts.errors.review_unconfirmed'),
                ['category' => 'review_unconfirmed', 'provider_state' => $state->value],
            );
        } else {
            $this->postPlatform->update([
                'platform_url' => $platformUrl,
                'last_reconciled_at' => now(),
            ]);

            return;
        }

        $this->postPlatform->update(['last_reconciled_at' => now()]);
        $this->settle();
    }

    public function failed(?Throwable $exception): void
    {
        $this->postPlatform->refresh();

        if ($this->postPlatform->status !== Status::PendingReview) {
            return;
        }

        $this->deferOrGiveUp(
            $exception instanceof GoogleBusinessPublishException
                ? $exception->userMessage
                : ($exception?->getMessage() ?: __('posts.errors.review_unconfirmed')),
        );
    }

    private function deferOrGiveUp(string $errorMessage): void
    {
        if ($this->reviewExpired()) {
            $this->giveUp(__('posts.errors.review_unconfirmed'), [
                'category' => 'review_unconfirmed',
                'detail' => $errorMessage,
            ]);

            return;
        }

        $this->postPlatform->update(['last_reconciled_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $errorContext
     */
    private function giveUp(string $errorMessage, array $errorContext = []): void
    {
        $this->postPlatform->markAsRejected(
            (string) $this->postPlatform->platform_post_id,
            $this->postPlatform->platform_url,
            $errorMessage,
            $errorContext,
        );
        $this->postPlatform->update(['last_reconciled_at' => now()]);
        $this->settle();
    }

    private function reviewExpired(): bool
    {
        $submittedAt = $this->postPlatform->submitted_at;

        return $submittedAt instanceof CarbonInterface
            && $submittedAt->addHours(self::REVIEW_CEILING_HOURS)->isPast();
    }

    private function settle(): void
    {
        app(GoogleBusinessDerivativeCleaner::class)->cleanup($this->postPlatform->id);
        app(FinalizePostPublication::class)->handle($this->postPlatform->post);
        PostPlatformStatusUpdated::dispatch($this->postPlatform->fresh());
    }
}
