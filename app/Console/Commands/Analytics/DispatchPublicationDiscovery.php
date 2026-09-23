<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Models\SocialAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:dispatch-publication-discovery')]
#[Description('Dispatch incremental native publication discovery for eligible accounts')]
class DispatchPublicationDiscovery extends Command
{
    public function handle(): int
    {
        SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->with('analyticsSyncStates')
            ->reorder()
            ->lazyById(100)
            ->each(function (SocialAccount $account): void {
                $backfill = $account->analyticsSyncStates
                    ->first(fn ($state): bool => $state->collector === SyncCollector::PublicationBackfill);

                if ($backfill?->status === SyncStatus::Failed) {
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
