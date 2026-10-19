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

class CaptureAccountEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array<string, mixed>  $properties
     */
    public function __construct(
        public string $distinctId,
        public string $accountId,
        public string $event,
        public array $properties = [],
    ) {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $account = Account::query()->with('plan')->find($this->accountId);

        if (blank($account)) {
            return;
        }

        $postHog->capture($this->distinctId, $this->event, $this->properties, $account);
    }
}
