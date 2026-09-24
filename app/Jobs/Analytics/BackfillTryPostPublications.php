<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\SyncTryPostPublication;
use App\Dto\Analytics\TryPostPublicationIdentity;
use App\Models\PostPlatform;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackfillTryPostPublications implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    /** @param list<string> $postPlatformIds */
    public function __construct(public array $postPlatformIds)
    {
        $this->onQueue('analytics');
    }

    public function handle(SyncTryPostPublication $sync, ResolveAnalyticsAccountKey $accountKeys): void
    {
        $identities = [];

        PostPlatform::query()
            ->published()
            ->includedInAnalytics()
            ->whereIn('id', $this->postPlatformIds)
            ->whereNotNull('social_account_id')
            ->with(['post', 'socialAccount'])
            ->get()
            ->each(function (PostPlatform $postPlatform) use ($sync, $accountKeys, &$identities): void {
                $account = $postPlatform->socialAccount;

                if (! $account) {
                    return;
                }

                $identities[$account->id] ??= TryPostPublicationIdentity::fromAccount(
                    $account,
                    $accountKeys->for($account),
                );

                $sync->fromIdentity($identities[$account->id], $postPlatform, $account);
            });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Analytics TryPost publication backfill failed', [
            'first_post_platform_id' => $this->postPlatformIds[0] ?? null,
            'post_platform_count' => count($this->postPlatformIds),
            'exception' => $exception,
        ]);
    }
}
