<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\SocialAccountEvent;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackSocialAccountDisconnected implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /**
     * @param  array{platform: string, network: string, workspace_id: string, reason: string, error_type?: string}  $snapshot
     */
    public function __construct(
        public ?string $socialAccountId,
        public string $accountId,
        public string $userId,
        public array $snapshot,
    ) {
        $this->onQueue('posthog');
    }

    /**
     * The properties of a channel that may be gone by the time the event is sent.
     *
     * @return array{platform: string, network: string, workspace_id: string, reason: string, error_type?: string}
     */
    public static function snapshotOf(SocialAccount $socialAccount, string $reason, bool $withErrorType = false): array
    {
        return [
            'platform' => $socialAccount->platform->value,
            'network' => $socialAccount->platform->network(),
            'workspace_id' => (string) $socialAccount->workspace_id,
            'reason' => $reason,
            ...($withErrorType ? ['error_type' => $reason] : []),
        ];
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

        $postHog->capture(
            $this->userId,
            SocialAccountEvent::Disconnected->value,
            [
                'social_account_id' => $this->socialAccountId,
                ...$this->snapshot,
            ],
            $account,
        );
    }
}
