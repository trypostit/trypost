<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Shared PostHog capture pipeline for jobs backed by a Stripe subscription
 * webhook payload. Subclasses provide the event name and its properties.
 */
abstract class AbstractTrackStripeSubscriptionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $accountId,
        public array $payload,
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::shouldTrack()) {
            return;
        }

        $account = Account::with('plan')->find($this->accountId);

        if (! $account?->owner_id || ! $account->plan) {
            return;
        }

        $postHog->capture(
            (string) $account->owner_id,
            $this->event(),
            $this->properties($account),
            $account,
        );
    }

    abstract protected function event(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function properties(Account $account): array;
}
