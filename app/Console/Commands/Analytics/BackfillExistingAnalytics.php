<?php

declare(strict_types=1);

namespace App\Console\Commands\Analytics;

use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('analytics:backfill-existing {--workspace= : Limit rollout to one workspace UUID}')]
#[Description('Dispatch resumable analytics backfills for existing accounts and publications')]
class BackfillExistingAnalytics extends Command
{
    public function handle(): int
    {
        $workspaceId = $this->option('workspace');
        $destinations = PostPlatform::query()
            ->published()
            ->includedInAnalytics()
            ->whereNotNull('social_account_id')
            ->when($workspaceId, fn ($query) => $query->whereHas(
                'post',
                fn ($post) => $post->where('workspace_id', $workspaceId),
            ));

        $destinations->lazyById(100)
            ->chunk(100)
            ->each(fn ($chunk) => BackfillTryPostPublications::dispatch(
                $chunk->pluck('id')->map(fn ($id): string => (string) $id)->values()->all(),
            ));

        $orphaned = PostPlatform::query()
            ->published()
            ->includedInAnalytics()
            ->whereNull('social_account_id')
            ->when($workspaceId, fn ($query) => $query->whereHas(
                'post',
                fn ($post) => $post->where('workspace_id', $workspaceId),
            ))
            ->count();

        SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->when($workspaceId, fn ($query) => $query->where('workspace_id', $workspaceId))
            ->lazyById(100)
            ->each(fn (SocialAccount $account) => BootstrapAccountAnalytics::dispatch($account->id));

        $this->info("historical_identity_unrecoverable={$orphaned}");

        return self::SUCCESS;
    }
}
