<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Enums\Analytics\SyncCollector;
use App\Jobs\Analytics\DiscoverAccountPublications;
use App\Models\AnalyticsSyncState;
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
            ->lazyById(100)
            ->each(function (SocialAccount $account): void {
                $backfillIsTerminal = AnalyticsSyncState::query()
                    ->where('social_account_id', $account->id)
                    ->forCollector(SyncCollector::PublicationBackfill)
                    ->terminal()
                    ->exists();
                $discovery = AnalyticsSyncState::query()
                    ->where('social_account_id', $account->id)
                    ->forCollector(SyncCollector::PublicationDiscovery)
                    ->first();

                if ($backfillIsTerminal && $discovery) {
                    DiscoverAccountPublications::dispatch($account->id, $discovery->id);
                }
            });

        return self::SUCCESS;
    }
}
