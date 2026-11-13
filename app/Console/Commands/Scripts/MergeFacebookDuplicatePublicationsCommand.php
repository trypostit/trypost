<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Post\ImportExternalPosts;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

#[Signature('analytics:merge-facebook-duplicates
    {--force : Run without confirmation in production}
    {--dry-run : Count the duplicates without changing anything}')]
#[Description('Merge each Facebook video, reel or photo discovered under its Page post id into the publication of the TryPost post it is')]
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
        $unmatched = 0;

        $this->candidates()->each(function (AnalyticsPublication $publication) use ($dryRun, $merged, &$unmatched): void {
            $account = $publication->socialAccount;
            $target = $account === null ? null : ImportExternalPosts::tryPostTarget($account, $publication);

            if ($target === null || (! $dryRun && ! ImportExternalPosts::mergeIntoTryPost($account, $publication->id))) {
                $unmatched++;

                return;
            }

            $merged->push($target->content_type->value);
        });

        $this->table(
            ['TryPost post type', $dryRun ? 'Would merge' : 'Merged'],
            $merged->countBy()->sortKeys()->map(fn (int $count, string $type): array => [$type, $count])->values()->all(),
        );
        $this->info(($dryRun ? 'Would merge' : 'Merged')." {$merged->count()} duplicate(s); {$unmatched} discovery(ies) are not a TryPost post.");

        return self::SUCCESS;
    }

    /**
     * @return LazyCollection<int, AnalyticsPublication>
     */
    private function candidates(): LazyCollection
    {
        return AnalyticsPublication::query()
            ->where('platform', Platform::Facebook)
            ->whereNull('post_id')
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('provider_metadata->video_id')
                ->orWhereNotNull('provider_metadata->photo_id'))
            ->with('socialAccount')
            ->lazyById(100);
    }
}
