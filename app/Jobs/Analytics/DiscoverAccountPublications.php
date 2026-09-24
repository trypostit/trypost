<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Enums\Analytics\SyncCollector;
use App\Models\AnalyticsSyncState;
use App\Models\SocialAccount;

class DiscoverAccountPublications extends AbstractPublicationSync
{
    protected function collector(): SyncCollector
    {
        return SyncCollector::PublicationDiscovery;
    }

    protected function mayStart(SocialAccount $account): bool
    {
        return AnalyticsSyncState::query()
            ->where('social_account_id', $account->id)
            ->forCollector(SyncCollector::PublicationBackfill)
            ->terminal()
            ->exists();
    }

    protected function restartTerminal(): bool
    {
        return AnalyticsSyncState::query()->find($this->syncStateId)?->isTerminal() ?? false;
    }

    protected function handleInvalidCursor(AdvanceAnalyticsSyncState $sync, SocialAccount $account, int $revision): void
    {
        $this->resetInvalidCursor($sync, $account, $revision);
    }
}
