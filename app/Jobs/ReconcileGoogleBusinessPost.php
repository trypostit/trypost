<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Post\FinalizePostPublication;
use App\Enums\PostPlatform\Status;
use App\Events\PostPlatformStatusUpdated;
use App\Models\PostPlatform;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\GoogleBusinessPublisher;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Settles a Google Business Profile target that the publish job had to leave
 * open. Google answers a create with 200 long before the post clears review,
 * so the state it reports later is what decides published vs. rejected.
 */
class ReconcileGoogleBusinessPost implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 600;

    /**
     * How long a target may sit in review before we stop believing it will
     * settle. Generous on purpose: a short ceiling fails posts that were only
     * slow, and Google normally clears review in minutes.
     */
    public const REVIEW_CEILING_HOURS = 24;

    /** States that mean the post is live and visible. */
    private const LIVE_STATES = ['LIVE', 'RECURRING'];

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

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $remote = app(GoogleBusinessPublisher::class)->fetchLocalPost($account, (string) $this->postPlatform->platform_post_id);
        $state = (string) (data_get($remote, 'state') ?: 'PROCESSING');
        $platformUrl = (string) (data_get($remote, 'searchUrl') ?: $this->postPlatform->platform_url);

        if (in_array($state, self::LIVE_STATES, true)) {
            $this->postPlatform->markAsPublished((string) $this->postPlatform->platform_post_id, $platformUrl);
        } elseif ($state === 'REJECTED') {
            $this->postPlatform->markAsRejected(
                (string) $this->postPlatform->platform_post_id,
                $platformUrl,
                __('posts.errors.rejected_in_review'),
                ['provider_state' => $state],
            );
        } elseif ($this->reviewExpired()) {
            $this->postPlatform->markAsRejected(
                (string) $this->postPlatform->platform_post_id,
                $platformUrl,
                __('posts.errors.review_unconfirmed'),
                ['category' => 'review_unconfirmed', 'provider_state' => $state],
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

    private function reviewExpired(): bool
    {
        $submittedAt = $this->postPlatform->submitted_at;

        return $submittedAt instanceof CarbonInterface
            && $submittedAt->addHours(self::REVIEW_CEILING_HOURS)->isPast();
    }

    private function settle(): void
    {
        app(FinalizePostPublication::class)->handle($this->postPlatform);
        PostPlatformStatusUpdated::dispatch($this->postPlatform->fresh());
    }
}
