<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Post\ImportExternalPosts;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

#[Signature('analytics:merge-facebook-duplicates
    {--force : Run without confirmation in production}
    {--dry-run : Count the duplicates without changing anything}')]
#[Description('Merge each Facebook video, reel or photo stored twice in Insights into the publication of its TryPost post')]
class MergeFacebookDuplicatePublicationsCommand extends Command
{
    use ConfirmableTrait;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        $merged = new Collection;

        AnalyticsPublication::query()
            ->where('platform', Platform::Facebook)
            ->whereNull('post_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('provider_metadata->video_id')
                ->orWhereNotNull('provider_metadata->photo_id'))
            ->with('socialAccount')
            ->lazyById()
            ->each(function (AnalyticsPublication $publication) use ($dryRun, $merged): void {
                $target = transform($publication->socialAccount, fn (SocialAccount $account): ?Post => ImportExternalPosts::tryPostTarget($account, $publication));

                if (blank($target) || (! $dryRun && ! ImportExternalPosts::linkToTryPost($publication->socialAccount, $publication->id))) {
                    return;
                }

                $merged->push($target->content_type->value);
            });

        $this->table(
            ['TryPost post type', $dryRun ? 'Would merge' : 'Merged'],
            $merged->countBy()->sortKeys()->map(fn (int $count, string $type): array => [$type, $count])->values()->all(),
        );
        $this->info(($dryRun ? 'Would merge' : 'Merged')." {$merged->count()} duplicate(s).");

        return self::SUCCESS;
    }
}
