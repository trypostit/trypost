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
            ->with(['analyticsSyncStates', 'workspace.account.subscriptions'])
            ->lazyById(100)
            ->each(function (SocialAccount $channel) use ($staleBefore): void {
                if (! $channel->workspace->account->hasAppAccess()) {
                    return;
                }

                $backfill = $channel->analyticsSyncStates
                    ->first(fn ($state): bool => $state->collector === SyncCollector::PublicationBackfill);

                if (! $backfill) {
                    BootstrapAccountAnalytics::dispatch($channel->id);

                    return;
                }

                if ($backfill->status === SyncStatus::Failed) {
                    if ($backfill->last_error_category === 'queue_failed') {
                        BootstrapAccountAnalytics::dispatch($channel->id);
                    }

                    return;
                }

                $staleBackfill = ($backfill->status === SyncStatus::Pending
                    || ($backfill->status === SyncStatus::Running && $backfill->last_error_category === null))
                    && $backfill->updated_at?->lessThan($staleBefore);

                if ($staleBackfill) {
                    BootstrapAccountAnalytics::dispatch($channel->id);

                    return;
                }

                $discovery = $channel->analyticsSyncStates
                    ->first(fn ($state): bool => $state->collector === SyncCollector::PublicationDiscovery);

                if ($backfill->isTerminal() && $discovery) {
                    DiscoverAccountPublications::dispatch($channel->id, $discovery->id);
                }
            });

        return self::SUCCESS;
    }
}
