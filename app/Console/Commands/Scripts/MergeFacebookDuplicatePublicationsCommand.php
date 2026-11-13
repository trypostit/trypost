<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Post\ImportExternalPosts;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

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

        $linked = new Collection;
        $failed = 0;
        $verb = $dryRun ? 'Would link' : 'Linked';

        AnalyticsPublication::query()
            ->where('platform', Platform::Facebook)
            ->whereNull('post_id')
            ->whereNull('post_dismissed_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereNotNull('provider_metadata->video_id')
                ->orWhereNotNull('provider_metadata->photo_id'))
            ->with('socialAccount')
            ->lazyById()
            ->each(function (AnalyticsPublication $publication) use ($dryRun, $linked, &$failed): void {
                try {
                    $post = $dryRun
                        ? $this->withoutWriting(fn (): ?Post => $this->link($publication))
                        : $this->link($publication);
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;

                    return;
                }

                if (filled($post)) {
                    $linked->push($post->content_type->value);
                }
            });

        $this->table(
            ['TryPost post type', $verb],
            $linked->countBy()->sortKeys()->map(fn (int $count, string $type): array => [$type, $count])->values()->all(),
        );
        $this->info("{$verb} {$linked->count()} publication(s) to their TryPost post.");

        if ($failed > 0) {
            $this->error("{$failed} publication(s) failed and were reported; run the command again once fixed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function link(AnalyticsPublication $publication): ?Post
    {
        return transform($publication->socialAccount, fn (SocialAccount $account): ?Post => ImportExternalPosts::linkToTryPost($account, $publication->id));
    }

    /**
     * Runs the real link and undoes it, so a dry run counts exactly what a run
     * would link. The link only writes rows of the database: these
     * publications have no post, so no imported post or media is deleted, and
     * nothing is queued.
     *
     * @param  Closure(): ?Post  $link
     */
    private function withoutWriting(Closure $link): ?Post
    {
        DB::beginTransaction();

        try {
            return $link();
        } finally {
            DB::rollBack();
        }
    }
}
