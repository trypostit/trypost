<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:dispatch-publication-discovery')]
#[Description('Dispatch incremental publication discovery and recover stale account backfills')]
class DispatchPublicationDiscovery extends Command
{
    public function handle(): int
    {
        $staleBefore = CarbonImmutable::now('UTC')->subHours(2);

        SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->with('analyticsSyncStates')
            ->reorder()
            ->lazyById(100)
            ->each(function (SocialAccount $account) use ($staleBefore): void {
                $backfill = $account->analyticsSyncStates
                    ->first(fn ($state): bool => $state->collector === SyncCollector::PublicationBackfill);

                if ($backfill?->status === SyncStatus::Failed) {
                    if ($backfill->last_error_category === 'queue_failed') {
                        BootstrapAccountAnalytics::dispatch($account->id);
                    }

                    return;
                }

                $staleBackfill = $backfill
                    && ($backfill->status === SyncStatus::Pending
                        || ($backfill->status === SyncStatus::Running && $backfill->last_error_category === null))
                    && $backfill->updated_at?->lessThan($staleBefore);

                if ($staleBackfill) {
                    BootstrapAccountAnalytics::dispatch($account->id);

                    return;
                }

                $backfillIsTerminal = $account->analyticsSyncStates
                    ->contains(fn ($state): bool => $state->collector === SyncCollector::PublicationBackfill && $state->isTerminal());
                $discovery = $account->analyticsSyncStates
                    ->first(fn ($state): bool => $state->collector === SyncCollector::PublicationDiscovery);

                if ($backfillIsTerminal && $discovery) {
                    DiscoverAccountPublications::dispatch($account->id, $discovery->id);
                }
            });

        return self::SUCCESS;
    }
}
