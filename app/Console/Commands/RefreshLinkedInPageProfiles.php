<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SocialAccount\RefreshLinkedInPageProfile;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class RefreshLinkedInPageProfiles extends Command
{
    protected $signature = 'social:refresh-linkedin-pages';

    protected $description = 'Read LinkedIn Page names and logos again before LinkedIn\'s storage limit, and clear the ones that can no longer be read';

    public function handle(): void
    {
        SocialAccount::query()
            ->where('platform', Platform::LinkedInPage)
            ->where(fn (Builder $query): Builder => $query
                ->where('status', Status::Connected)
                ->orWhereNotNull('display_name')
                ->orWhereNotNull('username')
                ->orWhereNotNull('avatar_url'))
            ->where(fn (Builder $query): Builder => $query
                ->where('profile_refreshed_at', '<=', now()->subWeeks(RefreshLinkedInPageProfile::REFRESH_AFTER_WEEKS))
                ->orWhere(fn (Builder $never): Builder => $never
                    ->whereNull('profile_refreshed_at')
                    ->where('created_at', '<=', now()->subWeeks(RefreshLinkedInPageProfile::REFRESH_AFTER_WEEKS))))
            ->chunkById(100, function (Collection $accounts): void {
                $accounts->each(fn (SocialAccount $account) => rescue(fn () => RefreshLinkedInPageProfile::execute($account)));
            });
    }
}
