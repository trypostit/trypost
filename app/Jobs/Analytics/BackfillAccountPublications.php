<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\AdvanceAnalyticsSyncState;
use App\Enums\Analytics\SyncCollector;
use App\Models\SocialAccount;

class BackfillAccountPublications extends AbstractPublicationSync
{
    protected function collector(): SyncCollector
    {
        return SyncCollector::PublicationBackfill;
    }

    protected function handleInvalidCursor(AdvanceAnalyticsSyncState $sync, SocialAccount $account, int $revision): void
    {
        $discoveryStateId = $sync->stopExpiredReconnectionCursor($this->syncStateId, $revision, $account);

        if ($discoveryStateId) {
            DiscoverAccountPublications::dispatch($account->id, $discoveryStateId)->afterCommit();

            return;
        }

        $this->resetInvalidCursor($sync, $account, $revision);
    }
}
