<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\RefreshSocialToken;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RefreshExpiringTokens extends Command
{
    protected $signature = 'social:refresh-expiring-tokens';

    protected $description = 'Proactively refresh social tokens before they expire';

    /**
     * Rotating platforms get a short lead — a wider window would only rotate
     * more often. Instagram and Threads can't be refreshed once expired, so
     * theirs is wide enough to survive queue backlog.
     */
    public function handle(): void
    {
        $count = 0;

        SocialAccount::query()
            ->where('status', Status::Connected)
            ->whereNotNull('token_expires_at')
            ->where(function (Builder $query) {
                $query->where(function (Builder $extension) {
                    $extension->whereIn('platform', Platform::accessTokenExtendingPlatformValues())
                        ->where('token_expires_at', '<=', now()->addDay());
                })->orWhere(function (Builder $rotating) {
                    $rotating->whereNotIn('platform', Platform::accessTokenExtendingPlatformValues())
                        ->where('token_expires_at', '<=', now()->addMinutes(30));
                });
            })
            ->chunk(50, function ($accounts) use (&$count) {
                foreach ($accounts as $account) {
                    RefreshSocialToken::dispatch($account);
                    $count++;
                }
            });

        // Accounts in the window, not jobs queued: the job is unique per
        // account, so a dispatch during a backlog is silently discarded.
        $this->info("{$count} accounts due for a token refresh.");
    }
}
