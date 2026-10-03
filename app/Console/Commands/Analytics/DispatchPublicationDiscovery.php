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
use Illuminate\Database\Eloquent\Builder;

#[Signature('analytics:dispatch-publication-discovery {--platform=* : Only these platforms} {--except-platform=* : Skip these platforms}')]
#[Description('Dispatch incremental publication discovery and recover stale account backfills')]
class DispatchPublicationDiscovery extends Command
{
    public function handle(): int
    {
        $staleBefore = CarbonImmutable::now('UTC')->subHours(2);

        $only = (array) $this->option('platform');
        $except = (array) $this->option('except-platform');

        SocialAccount::query()
            ->connected()
            ->includedInAnalytics()
            ->when($only !== [], fn (Builder $query): Builder => $query->whereIn('platform', $only))
            ->when($except !== [], fn (Builder $query): Builder => $query->whereNotIn('platform', $except))
            ->with('analyticsSyncStates')
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
