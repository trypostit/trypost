<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Services\Social\TikTokPublisher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('tiktok:repair-video-ids {--dry-run : List the repairs without writing them}')]
#[Description('Give TikTok posts back the videos a caption match handed to the post published after them')]
class RepairTikTokVideoIdsCommand extends Command
{
    /** TikTok creates the video about half a minute before the publish completes. */
    private const int CREATE_TO_PUBLISH_MINUTES = 2;

    /** A slow publish can finish many minutes after the video was created, so this alone proves nothing. */
    private const int LATE_VIDEO_MINUTES = 10;

    public function handle(): int
    {
        $repairs = $this->repairs();

        $this->table(
            ['Post', 'Published at', 'Held video', 'Own video'],
            $repairs->map(fn (array $repair): array => [
                data_get($repair, 'post.id'),
                data_get($repair, 'post.published_at')->toDateTimeString(),
                data_get($repair, 'held.remote_id'),
                data_get($repair, 'own.remote_id'),
            ])->all(),
        );

        $awaiting = Post::query()
            ->publishedToTikTok()
            ->whereHas('socialAccount', fn (Builder $query): Builder => $query->connected())
            ->lazyById()
            ->filter(fn (Post $post): bool => $post->awaitsTikTokVideoId())
            ->collect();

        if ($this->option('dry-run')) {
            $this->info("{$repairs->count()} post(s) would get their own video back; {$awaiting->count()} post(s) would ask TikTok for their video id.");

            return self::SUCCESS;
        }

        DB::transaction(function () use ($repairs): void {
            $repairs->each(fn (array $repair) => data_get($repair, 'held')->update([
                'post_id' => null,
                'origin' => PublicationOrigin::External,
            ]));

            $repairs->each(fn (array $repair) => $this->giveBack(data_get($repair, 'post'), data_get($repair, 'own')->fresh()));
        });

        $awaiting->each(fn (Post $post) => ResolveTikTokVideoId::dispatch($post));

        $this->info("{$repairs->count()} post(s) got their own video back; {$awaiting->count()} post(s) are asking TikTok for their video id.");

        return self::SUCCESS;
    }

    /**
     * A post holds the wrong video when that video was created right before
     * another post of the channel was published, and one video was created
     * right before the post itself.
     *
     * @return Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>
     */
    private function repairs(): Collection
    {
        $repairs = AnalyticsPublication::query()
            ->where('network', Platform::TikTok->network())
            ->whereHas('post', fn (Builder $query): Builder => $query->publishedToTikTok()->whereNotNull('published_at')->has('socialAccount'))
            ->with('post.socialAccount')
            ->lazyById()
            ->filter(fn (AnalyticsPublication $held): bool => ctype_digit($held->remote_id)
                && $held->provider_published_at->lessThan($held->post->published_at->subMinutes(self::LATE_VIDEO_MINUTES))
                && $this->publishedRightAfter($held))
            ->map(fn (AnalyticsPublication $held): array => [
                'post' => $held->post,
                'held' => $held,
                'own' => $this->ownVideo($held),
            ])
            ->filter(fn (array $repair): bool => filled(data_get($repair, 'own')))
            ->values()
            ->collect();

        do {
            $before = $repairs->count();
            $repairs = $this->withFreeOwnVideo($repairs);
        } while ($repairs->count() !== $before);

        return $repairs;
    }

    /**
     * Keeps a repair only when its own video is claimed by no other repair and
     * is free once the repairs run: unlinked, imported, or held by a post that
     * is itself repaired. Dropping one repair can strand another, so the caller
     * repeats this until nothing changes.
     *
     * @param  Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>  $repairs
     * @return Collection<int, array{post: Post, held: AnalyticsPublication, own: AnalyticsPublication}>
     */
    private function withFreeOwnVideo(Collection $repairs): Collection
    {
        $repairedPostIds = $repairs->map(fn (array $repair): string => data_get($repair, 'post.id'));
        $claims = $repairs->countBy(fn (array $repair): string => data_get($repair, 'own.id'));

        return $repairs
            ->filter(fn (array $repair): bool => $claims->get(data_get($repair, 'own.id')) === 1
                && (blank(data_get($repair, 'own.post_id'))
                    || $repairedPostIds->contains(data_get($repair, 'own.post_id'))
                    || Post::query()->imported()->whereKey(data_get($repair, 'own.post_id'))->exists()))
            ->values();
    }

    private function publishedRightAfter(AnalyticsPublication $held): bool
    {
        $createdAt = $held->provider_published_at;

        return Post::query()
            ->createdInTryPost()
            ->where('social_account_id', $held->post->social_account_id)
            ->whereKeyNot($held->post_id)
            ->whereBetween('published_at', [$createdAt, $createdAt->addMinutes(self::CREATE_TO_PUBLISH_MINUTES)])
            ->exists();
    }

    private function ownVideo(AnalyticsPublication $held): ?AnalyticsPublication
    {
        $publishedAt = $held->post->published_at;

        $candidates = AnalyticsPublication::query()
            ->where('workspace_id', $held->workspace_id)
            ->where('social_account_key', $held->social_account_key)
            ->where('network', $held->network)
            ->whereKeyNot($held->id)
            ->whereBetween('provider_published_at', [$publishedAt->subMinutes(self::CREATE_TO_PUBLISH_MINUTES), $publishedAt])
            ->limit(2)
            ->get();

        return $candidates->count() === 1 && ctype_digit($candidates->sole()->remote_id) ? $candidates->sole() : null;
    }

    private function giveBack(Post $post, AnalyticsPublication $own): void
    {
        $imported = filled($own->post_id) ? Post::query()->imported()->whereKey($own->post_id)->first() : null;

        if (filled($imported)) {
            DeleteOwnedMedia::forPosts([$imported->id]);
            Post::withoutEvents(fn (): ?bool => $imported->delete());
        }

        $own->update(['post_id' => $post->id, 'origin' => PublicationOrigin::TryPost]);

        $post->writePublication([
            'platform_post_id' => $own->remote_id,
            'platform_url' => $own->permalink
                ?? TikTokPublisher::postUrl($post->socialAccount, $own->remote_id)
                ?? $post->platform_url,
        ]);
    }
}
