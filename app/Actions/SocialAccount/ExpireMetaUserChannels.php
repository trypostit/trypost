<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\Notification\Type;
use App\Enums\SocialAccount\Status;
use App\Jobs\SendNotification;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\SocialAccount;
use Illuminate\Support\Collection;

/**
 * Flags channels whose Meta authorization ended for reconnection, and tells
 * each workspace owner once about all of theirs, as the daily connection check
 * does, instead of one email per channel.
 */
class ExpireMetaUserChannels
{
    /**
     * @param  Collection<int, SocialAccount>  $accounts  with `workspace.owner` loaded
     */
    public static function execute(Collection $accounts, string $reason): void
    {
        $wereConnected = $accounts->filter(fn (SocialAccount $account): bool => $account->status === Status::Connected);

        $accounts->each(fn (SocialAccount $account) => $account->markAsTokenExpired($reason, notify: false));

        $wereConnected->groupBy('workspace_id')->each(function (Collection $channels): void {
            $workspace = $channels->first()->workspace;

            if ($workspace?->owner === null) {
                return;
            }

            SendNotification::dispatch(
                user: $workspace->owner,
                type: Type::AccountDisconnected,
                mailable: new WorkspaceConnectionsDisconnected($workspace, $channels->values()),
            );
        });
    }
}
