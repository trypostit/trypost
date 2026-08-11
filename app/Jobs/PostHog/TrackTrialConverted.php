<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\TrialEvent;
use App\Models\Account;
use App\Services\PostHogService;
use App\Support\StripeSubscriptionConversion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackTrialConverted implements ShouldQueue
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
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Account::with(['plan', 'owner'])->find($this->accountId);

        if (! $account || ! $account->owner_id || ! $account->plan) {
            return;
        }

        $postHog->capture(
            (string) $account->owner_id,
            TrialEvent::Converted->value,
            StripeSubscriptionConversion::propertiesFor($account, $this->payload),
            $account,
        );
    }
}
