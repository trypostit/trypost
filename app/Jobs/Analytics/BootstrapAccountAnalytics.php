<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class BootstrapAccountAnalytics implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $socialAccountId)
    {
        $this->onQueue('analytics');
    }

    public function handle(): void
    {
        $this->handleFor($this->socialAccountId);
    }

    public function handleFor(string $socialAccountId): void
    {
        $account = SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->find($socialAccountId);

        if (! $account || ! app(PublicationHistoryCollectorFactory::class)->supports($account->platform)) {
            return;
        }

        $backfill = AnalyticsSyncState::query()->firstOrCreate([
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationBackfill,
        ], [
            'status' => SyncStatus::Pending,
            'checkpoint' => ['cursor' => null, 'revision' => 0],
            'target_since' => CarbonImmutable::now('UTC')->subDays(365),
        ]);

        AnalyticsSyncState::query()->firstOrCreate([
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationDiscovery,
        ], [
            'status' => SyncStatus::Pending,
            'checkpoint' => ['cursor' => null, 'revision' => 0],
        ]);

        if (in_array($backfill->status, [SyncStatus::Partial, SyncStatus::Failed], true)) {
            $backfill->update([
                'status' => SyncStatus::Pending,
                'checkpoint' => [
                    'cursor' => $backfill->status === SyncStatus::Failed
                        ? data_get($backfill->checkpoint, 'cursor')
                        : null,
                    'revision' => (int) data_get($backfill->checkpoint, 'revision', 0),
                    ...($account->platform === Platform::X
                        ? ['seen_count' => $backfill->status === SyncStatus::Failed
                            ? (int) data_get($backfill->checkpoint, 'seen_count', 0)
                            : 0]
                        : []),
                ],
            ]);
        }

        if (! $backfill->isTerminal()) {
            BackfillAccountPublications::dispatch($account->id, $backfill->id)->afterCommit();
        }
    }
}
